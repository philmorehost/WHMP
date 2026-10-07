<?php

declare(strict_types=1);

namespace CodeVault\UsernameChanger;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Database;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Queue\QueueInterface;
use CodeVault\Reseller\ResellerLedgerService;
use DateTimeImmutable;
use Throwable;

/**
 * The request lifecycle (plan §4):
 *
 *   awaiting_confirmation ─confirm─► pending_approval ─approve─► awaiting_payment ─paid─► queued ─► processing ─► completed
 *        │ (email link / PIN)            │ (admin or store)          │ (fee ON only)                  └─► failed ─retry─► queued
 *        └─ expire ─► expired             └─ decline ─► declined      └─ invoice cancelled ─► cancelled
 *
 * Approval comes BEFORE payment on purpose: nobody is ever charged for a change
 * that is then declined, so there is nothing to refund.
 *
 * Every method returns ['ok' => bool, 'message' => string, ...]. Nothing here
 * talks to WHM except through UsernameAvailability::deep() at submit time and
 * the executor at run time.
 */
final class UsernameChangeService
{
    public function __construct(
        private readonly UsernameChangeRepository $requests,
        private readonly PolicyResolver $policies,
        private readonly UsernameAvailability $availability,
        private readonly UsernameChangerSettings $settings,
        private readonly UsernameChangeNotifier $notifier,
        private readonly Database $db,
        private readonly ?InvoiceRepository $invoices = null,
        private readonly ?CurrencyService $currency = null,
        private readonly ?ResellerLedgerService $ledger = null,
        private readonly ?QueueInterface $queue = null,
        private readonly ?ActivityLogger $activity = null,
        private readonly ?HookDispatcher $hooks = null
    ) {
    }

    /**
     * A client (or a store owner acting for its own service) asks for a new name.
     *
     * @param array{reason?: string, rename_db?: bool, method?: string, pin?: string, acknowledged?: bool, ip?: string, actor_type?: string, actor_id?: int} $opts
     * @return array{ok: bool, message: string, request_id?: int, status?: string, code?: string}
     */
    public function request(int $serviceId, string $newUsername, array $opts = []): array
    {
        $policy = $this->policies->forService($serviceId);

        if (!($policy['eligible'] ?? false)) {
            return self::fail('Username changes are not available for this service.', 'ineligible');
        }

        if (!$policy['can_request']) {
            return self::fail((string) $policy['blocked_reason'], 'blocked');
        }

        if (empty($opts['acknowledged'])) {
            return self::fail('Please tick each box to confirm you understand what changes.', 'ack');
        }

        $reason = trim((string) ($opts['reason'] ?? ''));

        if ($this->settings->requireReason() && $reason === '') {
            return self::fail('Please tell us why you want to change the username.', 'reason');
        }

        if ($this->requests->openForService($serviceId) !== null) {
            return self::fail('There is already a username change in progress for this service.', 'open');
        }

        $methods = $this->settings->confirmMethods();
        $method = (string) ($opts['method'] ?? $methods[0]);

        if (!in_array($method, $methods, true) || ($method === 'pin' && !$policy['has_pin'])) {
            $method = in_array('email', $methods, true) ? 'email' : '';
        }

        if ($method === '') {
            return self::fail('Set a Security PIN on your account to confirm username changes.', 'method');
        }

        $name = UsernamePolicy::normalise($newUsername);
        $service = ['id' => $serviceId, 'server_id' => $policy['server_id'], 'username' => $policy['username']];
        $check = $this->availability->deep($name, $service);

        if (!$check['ok']) {
            return self::fail($check['message'], $check['code']);
        }

        $ip = $opts['ip'] ?? null;
        $actorType = (string) ($opts['actor_type'] ?? 'client');
        $actorId = isset($opts['actor_id']) ? (int) $opts['actor_id'] : $policy['client_id'];

        // PIN: checked BEFORE anything is created, so a wrong PIN leaves no row.
        if ($method === 'pin') {
            if (!$this->requests->hit('pin:' . $policy['client_id'], 5, 3600)) {
                return self::fail('Too many PIN attempts. Please confirm by email instead, or try again later.', 'pin_locked');
            }

            if (!$this->pinMatches((int) $policy['client_id'], (string) ($opts['pin'] ?? ''))) {
                return self::fail('That Security PIN is not correct.', 'pin');
            }
        }

        $token = null;
        $row = [
            'service_id' => $serviceId,
            'client_id' => $policy['client_id'],
            'reseller_id' => $policy['store_id'],
            'server_id' => $policy['server_id'],
            'old_username' => $policy['username'],
            'new_username' => $name,
            'reason' => $reason === '' ? null : mb_substr($reason, 0, 500),
            'rename_db_objects' => !empty($opts['rename_db']) && $policy['allow_db_rename'] ? 1 : 0,
            'status' => 'awaiting_confirmation',
            'confirm_method' => $method,
            'requested_by_type' => $actorType,
            'requested_by_id' => $actorId,
            'ip' => $ip,
        ];

        if ($method === 'email') {
            $token = bin2hex(random_bytes(32));
            $row['confirm_token_hash'] = hash('sha256', $token);
            $row['confirm_expires_at'] = (new DateTimeImmutable('+' . $this->settings->confirmTtlHours() . ' hours'))->format('Y-m-d H:i:s');
            $row['confirm_sent_count'] = 1;
            $row['confirm_last_sent_at'] = UsernameChangeRepository::now();
        }

        try {
            $id = $this->requests->create($row);
        } catch (Throwable) {
            return self::fail('We could not save your request. Please try again.', 'error');
        }

        $this->requests->event($id, 'requested', $actorType, $actorId, $ip, $policy['username'] . ' → ' . $name);

        if ($method === 'pin') {
            $this->requests->event($id, 'confirmed', $actorType, $actorId, $ip, 'Security PIN');

            return $this->afterConfirmed($id, $ip) + ['request_id' => $id];
        }

        $detail = $this->requests->findDetailed($id);

        if ($detail !== null && $token !== null) {
            $this->notifier->confirm($detail, $token);
            $this->requests->event($id, 'confirm_sent', 'system', null, $ip);
        }

        return ['ok' => true, 'message' => 'Check your email — we sent a link to confirm the change.', 'request_id' => $id, 'status' => 'awaiting_confirmation'];
    }

    /**
     * The emailed link. $siteStoreId is the store whose host the link was opened
     * on (null = the platform): a token only works on the site it was issued for.
     *
     * @return array{ok: bool, message: string, request?: array<string, mixed>}
     */
    public function confirmByToken(string $token, ?int $siteStoreId, ?string $ip = null): array
    {
        $request = $this->findByToken($token, $siteStoreId);

        if ($request === null) {
            return self::fail('This confirmation link is invalid or has already been used.');
        }

        if ((string) $request['status'] !== 'awaiting_confirmation') {
            return self::fail('This request has already been confirmed or is no longer pending.');
        }

        if ($request['confirm_expires_at'] !== null && new DateTimeImmutable((string) $request['confirm_expires_at']) < new DateTimeImmutable()) {
            $this->expire($request);

            return self::fail('This confirmation link has expired. Please start a new request.');
        }

        $id = (int) $request['id'];

        if (!$this->requests->transition($id, 'awaiting_confirmation', 'awaiting_confirmation', ['confirm_token_hash' => null, 'confirmed_at' => UsernameChangeRepository::now()])) {
            return self::fail('This request is no longer pending.');
        }

        $this->requests->event($id, 'confirmed', 'client', (int) $request['client_id'], $ip, 'Email link');

        return $this->afterConfirmed($id, $ip) + ['request' => $request];
    }

    /** @return array<string, mixed>|null a still-valid token's request, for the confirm page */
    public function findByToken(string $token, ?int $siteStoreId): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $request = $this->requests->findByTokenHash(hash('sha256', $token));

        if ($request === null) {
            return null;
        }

        $issuedFor = ($request['reseller_id'] ?? null) === null ? null : (int) $request['reseller_id'];

        return $issuedFor === $siteStoreId ? $request : null;
    }

    /** @return array{ok: bool, message: string} */
    public function resend(int $requestId, int $clientId, ?string $ip = null): array
    {
        $r = $this->requests->find($requestId);

        if ($r === null || (int) $r['client_id'] !== $clientId || (string) $r['status'] !== 'awaiting_confirmation' || $r['confirm_method'] !== 'email') {
            return self::fail('There is nothing to resend.');
        }

        if ((int) $r['confirm_sent_count'] >= 4) {
            return self::fail('We have already sent this email several times. Please check your spam folder or cancel and start again.');
        }

        if ($r['confirm_last_sent_at'] !== null && strtotime((string) $r['confirm_last_sent_at']) > time() - 300) {
            return self::fail('Please wait a few minutes before asking for another email.');
        }

        $token = bin2hex(random_bytes(32));
        $this->requests->patch($requestId, [
            'confirm_token_hash' => hash('sha256', $token),
            'confirm_expires_at' => (new DateTimeImmutable('+' . $this->settings->confirmTtlHours() . ' hours'))->format('Y-m-d H:i:s'),
            'confirm_sent_count' => (int) $r['confirm_sent_count'] + 1,
            'confirm_last_sent_at' => UsernameChangeRepository::now(),
        ]);

        $detail = $this->requests->findDetailed($requestId);

        if ($detail !== null) {
            $this->notifier->confirm($detail, $token);
        }

        $this->requests->event($requestId, 'confirm_sent', 'client', $clientId, $ip, 'Resent');

        return ['ok' => true, 'message' => 'We sent the confirmation email again.'];
    }

    /** @return array{ok: bool, message: string} */
    public function cancel(int $requestId, string $actorType, ?int $actorId, ?string $ip = null, ?int $mustBeClientId = null): array
    {
        $r = $this->requests->find($requestId);

        if ($r === null || ($mustBeClientId !== null && (int) $r['client_id'] !== $mustBeClientId)) {
            return self::fail('Request not found.');
        }

        // A client may cancel until the rename is queued; staff until it starts.
        $from = $actorType === 'admin'
            ? ['awaiting_confirmation', 'pending_approval', 'awaiting_payment', 'queued', 'failed']
            : ['awaiting_confirmation', 'pending_approval', 'awaiting_payment'];

        if (!$this->requests->transition($requestId, $from, 'cancelled', ['confirm_token_hash' => null, 'decided_by_type' => $actorType, 'decided_by_id' => $actorId, 'decided_at' => UsernameChangeRepository::now()])) {
            return self::fail('This request can no longer be cancelled.');
        }

        $this->cancelInvoice($r);
        $this->requests->event($requestId, 'cancelled', $actorType, $actorId, $ip);

        return ['ok' => true, 'message' => 'The request was cancelled.'];
    }

    /** @return array{ok: bool, message: string} */
    public function approve(int $requestId, string $actorType, ?int $actorId, ?string $ip = null, ?int $mustBeStoreId = null): array
    {
        $r = $this->requests->find($requestId);

        if ($r === null || ($mustBeStoreId !== null && (int) ($r['reseller_id'] ?? 0) !== $mustBeStoreId)) {
            return self::fail('Request not found.');
        }

        if ($actorType === 'reseller' && !$this->storeMayDecide($r)) {
            return self::fail('This request is waiting for the platform\'s approval, not yours.');
        }

        if (!$this->requests->transition($requestId, 'pending_approval', 'pending_approval', ['decided_by_type' => $actorType, 'decided_by_id' => $actorId, 'decided_at' => UsernameChangeRepository::now()])) {
            return self::fail('This request is not waiting for approval.');
        }

        $this->requests->event($requestId, 'approved', $actorType, $actorId, $ip);
        $detail = $this->requests->findDetailed($requestId);

        if ($detail !== null) {
            $this->notifier->approved($detail);
        }

        return $this->toPaymentOrQueue($requestId, $ip);
    }

    /** @return array{ok: bool, message: string} */
    public function decline(int $requestId, string $reason, string $actorType, ?int $actorId, ?string $ip = null, ?int $mustBeStoreId = null): array
    {
        $reason = trim($reason);
        $r = $this->requests->find($requestId);

        if ($r === null || ($mustBeStoreId !== null && (int) ($r['reseller_id'] ?? 0) !== $mustBeStoreId)) {
            return self::fail('Request not found.');
        }

        if ($reason === '') {
            return self::fail('Please give a reason — the client will see it.');
        }

        if ($actorType === 'reseller' && !$this->storeMayDecide($r)) {
            return self::fail('This request is waiting for the platform\'s approval, not yours.');
        }

        if (!$this->requests->transition($requestId, 'pending_approval', 'declined', ['decline_reason' => mb_substr($reason, 0, 500), 'decided_by_type' => $actorType, 'decided_by_id' => $actorId, 'decided_at' => UsernameChangeRepository::now()])) {
            return self::fail('This request is not waiting for approval.');
        }

        $this->requests->event($requestId, 'declined', $actorType, $actorId, $ip, $reason);
        $detail = $this->requests->findDetailed($requestId);

        if ($detail !== null) {
            $this->notifier->declined($detail);
        }

        return ['ok' => true, 'message' => 'The request was declined and the client has been told why.'];
    }

    /** Staff: put a failed request back in the queue now. @return array{ok: bool, message: string} */
    public function retry(int $requestId, ?int $adminId, ?string $ip = null): array
    {
        if (!$this->requests->transition($requestId, 'failed', 'queued', ['next_attempt_at' => null, 'attempts' => 0, 'lock_token' => null])) {
            return self::fail('Only a failed request can be retried.');
        }

        $this->requests->event($requestId, 'retried', 'admin', $adminId, $ip);
        $this->dispatch($requestId);

        return ['ok' => true, 'message' => 'The request was queued again.'];
    }

    /** Staff: let the change go ahead without payment. @return array{ok: bool, message: string} */
    public function waivePayment(int $requestId, ?int $adminId, ?string $ip = null): array
    {
        $r = $this->requests->find($requestId);

        if ($r === null || (string) $r['status'] !== 'awaiting_payment') {
            return self::fail('This request is not waiting for payment.');
        }

        if (!$this->requests->transition($requestId, 'awaiting_payment', 'queued', ['fee_amount' => null])) {
            return self::fail('This request is not waiting for payment.');
        }

        $this->cancelInvoice($r);
        $this->requests->event($requestId, 'payment_waived', 'admin', $adminId, $ip);
        $this->dispatch($requestId);

        return ['ok' => true, 'message' => 'Payment waived — the change is queued.'];
    }

    /**
     * Super admin: rename now. Skips confirmation, approval, limits, cooldown and
     * payment — never the safety rules, which the executor runs again.
     *
     * @return array{ok: bool, message: string, request_id?: int}
     */
    public function adminRename(int $serviceId, string $newUsername, bool $renameDb, int $adminId, ?string $ip = null, bool $preflightOnly = false): array
    {
        $ctx = $this->policies->forService($serviceId);

        if (($ctx['reason'] ?? null) === 'not_found') {
            return self::fail('Service not found.');
        }

        if (in_array($ctx['reason'] ?? null, ['not_cpanel', 'no_username'], true)) {
            return self::fail($ctx['reason'] === 'not_cpanel' ? 'This service is not on a cPanel server.' : 'This service has no cPanel username yet.');
        }

        if ($this->requests->openForService($serviceId) !== null) {
            return self::fail('This service already has a username change in progress. Finish or cancel it first.');
        }

        $name = UsernamePolicy::normalise($newUsername);
        $check = $this->availability->deep($name, ['id' => $serviceId, 'server_id' => $ctx['server_id'], 'username' => $ctx['username']]);

        if (!$check['ok']) {
            return self::fail($check['message'], $check['code']);
        }

        if ($preflightOnly) {
            return ['ok' => true, 'message' => "Preflight passed: “{$name}” is valid and free on the server. Nothing was changed."];
        }

        $id = $this->requests->create([
            'service_id' => $serviceId,
            'client_id' => $ctx['client_id'],
            'reseller_id' => $ctx['store_id'],
            'server_id' => $ctx['server_id'],
            'old_username' => $ctx['username'],
            'new_username' => $name,
            'rename_db_objects' => $renameDb ? 1 : 0,
            'status' => 'queued',
            'confirm_method' => 'admin',
            'confirmed_at' => UsernameChangeRepository::now(),
            'requested_by_type' => 'admin',
            'requested_by_id' => $adminId,
            'ip' => $ip,
        ]);

        $this->requests->event($id, 'requested', 'admin', $adminId, $ip, 'Manual change by staff: ' . $ctx['username'] . ' → ' . $name);
        $this->dispatch($id, true);
        $after = $this->requests->find($id);
        $status = (string) ($after['status'] ?? 'queued');

        return [
            'ok' => $status !== 'failed',
            'message' => match ($status) {
                'completed' => "Done — the username is now “{$name}”.",
                'failed' => 'The rename failed: ' . (string) ($after['last_error'] ?? 'unknown error'),
                default => 'The rename is queued and will run in the background.',
            },
            'request_id' => $id,
        ];
    }

    /**
     * Payment hook: an invoice was paid. Advances every request waiting on it
     * (including when it was paid as part of a consolidated "pay all" invoice)
     * and credits the resellers' margins exactly once.
     */
    public function invoicePaid(int $invoiceId): void
    {
        $ids = [$invoiceId];

        try {
            foreach ($this->db->select('SELECT id FROM invoices WHERE parent_invoice_id = ?', [$invoiceId]) as $child) {
                $ids[] = (int) $child['id'];
            }
        } catch (Throwable) {
        }

        foreach ($ids as $id) {
            foreach ($this->requests->byInvoice($id) as $r) {
                $this->settlePaid($r);
            }
        }
    }

    /** Refund hook: take the resellers' shares back. Never un-renames anything. */
    public function invoiceRefunded(int $invoiceId): void
    {
        foreach ($this->requests->byInvoice($invoiceId) as $r) {
            if ($this->ledger === null || !$this->requests->claimFeeReversal((int) $r['id'])) {
                continue;
            }

            $this->postShares($r, true);
            $this->requests->event((int) $r['id'], 'fee_reversed', 'system', null, null, 'Invoice #' . $invoiceId . ' refunded');
        }
    }

    /**
     * Cron housekeeping on payment: requests whose invoice was paid by a route
     * that fired no hook are advanced, and those whose invoice was cancelled are
     * cancelled with it.
     */
    public function reconcilePayments(): void
    {
        foreach ($this->requests->awaitingPayment() as $r) {
            $state = (string) ($r['invoice_status'] ?? '');

            if ($state === 'paid') {
                $this->settlePaid($r);
            } elseif (in_array($state, ['cancelled', ''], true) && $r['invoice_id'] !== null) {
                if ($this->requests->transition((int) $r['id'], 'awaiting_payment', 'cancelled', ['decided_by_type' => 'system', 'decided_at' => UsernameChangeRepository::now()])) {
                    $this->requests->event((int) $r['id'], 'cancelled', 'system', null, null, 'Invoice cancelled');
                }
            }
        }
    }

    /** @param array<string, mixed> $request */
    public function expire(array $request): void
    {
        if ($this->requests->transition((int) $request['id'], 'awaiting_confirmation', 'expired', ['confirm_token_hash' => null])) {
            $this->requests->event((int) $request['id'], 'expired', 'system');
        }
    }

    /** Hands a queued request to the executor — inline, or on the queue. */
    public function dispatch(int $requestId, bool $forceNow = false): void
    {
        if (!$forceNow && $this->settings->execution() === 'queued') {
            return; // cron picks it up within 5 minutes
        }

        try {
            if ($this->queue !== null) {
                $this->queue->push(new UsernameChangeJob($requestId));
            } else {
                (new UsernameChangeJob($requestId))->handle();
            }
        } catch (Throwable) {
            // Left queued: cron retries it.
        }
    }

    // ----------------------------------------------------------------- internals

    /** @return array{ok: bool, message: string, status?: string} */
    private function afterConfirmed(int $id, ?string $ip): array
    {
        $r = $this->requests->find($id);

        if ($r === null) {
            return self::fail('Request not found.');
        }

        $policy = $this->policies->forService((int) $r['service_id']);

        if (in_array($policy['approval'] ?? 'none', ['admin', 'reseller'], true)) {
            $this->requests->transition($id, 'awaiting_confirmation', 'pending_approval', ['confirmed_at' => $r['confirmed_at'] ?? UsernameChangeRepository::now(), 'confirm_token_hash' => null]);
            $detail = $this->requests->findDetailed($id);

            if ($detail !== null) {
                $this->notifier->submitted($detail);
                $this->notifier->staffNew($detail);

                if ($policy['approval'] === 'reseller') {
                    $this->notifier->storeApproval($detail);
                }
            }

            return ['ok' => true, 'message' => 'Confirmed. Your request is waiting for approval — we will email you.', 'status' => 'pending_approval'];
        }

        $this->requests->transition($id, 'awaiting_confirmation', 'pending_approval', ['confirmed_at' => $r['confirmed_at'] ?? UsernameChangeRepository::now(), 'confirm_token_hash' => null]);

        return $this->toPaymentOrQueue($id, $ip, true);
    }

    /**
     * From pending_approval (approved, or approval not needed): raise the fee
     * invoice when payment is on, otherwise queue the rename.
     *
     * @return array{ok: bool, message: string, status?: string}
     */
    private function toPaymentOrQueue(int $id, ?string $ip, bool $tellClient = false): array
    {
        $r = $this->requests->find($id);

        if ($r === null) {
            return self::fail('Request not found.');
        }

        $policy = $this->policies->forService((int) $r['service_id']);
        $pricing = $policy['pricing'] ?? ['charge' => false];

        if (($pricing['charge'] ?? false) && $r['requested_by_type'] !== 'admin' && $this->invoices !== null && $this->currency !== null) {
            $invoice = $this->raiseInvoice($r, $pricing);

            if ($invoice !== null) {
                $this->requests->transition($id, 'pending_approval', 'awaiting_payment', [
                    'invoice_id' => $invoice['id'],
                    'fee_amount' => $invoice['amount'],
                    'fee_currency_id' => $invoice['currency_id'],
                    'fee_retail' => $pricing['price'],
                    'fee_cost' => $pricing['cost'],
                    'fee_upline_cost' => $pricing['upline_cost'],
                ]);
                $this->requests->event($id, 'invoiced', 'system', null, $ip, 'Invoice #' . $invoice['id'] . ' — ' . $invoice['label']);
                $detail = $this->requests->findDetailed($id);

                if ($detail !== null) {
                    $this->notifier->paymentDue($detail, $invoice['label']);
                }

                return ['ok' => true, 'message' => 'Confirmed. Pay invoice #' . $invoice['id'] . ' (' . $invoice['label'] . ') and the change runs automatically.', 'status' => 'awaiting_payment', 'invoice_id' => $invoice['id']];
            }

            return self::fail('We could not raise the invoice for this change. Please contact support.');
        }

        $this->requests->transition($id, 'pending_approval', 'queued');
        $this->requests->event($id, 'queued', 'system', null, $ip);

        if ($tellClient) {
            $detail = $this->requests->findDetailed($id);

            if ($detail !== null) {
                $this->notifier->submitted($detail);
            }
        }

        $this->dispatch($id);
        $after = $this->requests->find($id);
        $status = (string) ($after['status'] ?? 'queued');

        return ['ok' => true, 'message' => match ($status) {
            'completed' => 'Done — your cPanel username is now “' . $r['new_username'] . '”.',
            'failed' => 'We could not complete the change. Our team has been notified.',
            default => 'Confirmed. The change is queued and usually completes within a few minutes.',
        }, 'status' => $status];
    }

    /**
     * @param array<string, mixed> $r
     * @param array<string, mixed> $pricing
     * @return array{id: int, amount: float, currency_id: ?int, label: string}|null
     */
    private function raiseInvoice(array $r, array $pricing): ?array
    {
        try {
            $client = $this->db->selectOne('SELECT * FROM clients WHERE id = ?', [(int) $r['client_id']]);
            $currency = $this->currency->resolveForClient($client);
            $amount = $this->currency->convert((float) $pricing['price'], $this->currency->catalogRate($currency));
            $cols = $this->currency->denominateColumns($currency);
            $domain = (string) ($this->db->selectOne('SELECT domain FROM services WHERE id = ?', [(int) $r['service_id']])['domain'] ?? '');
            $description = 'cPanel username change' . ($domain !== '' ? " — {$domain}" : '') . ': ' . $r['old_username'] . ' → ' . $r['new_username'] . ' (request #' . $r['id'] . ')';
            $invoiceId = $this->invoices->createFromItems((int) $r['client_id'], [['description' => $description, 'amount' => $amount]], $cols['currency_id'], (float) $cols['currency_rate'], null, 3);

            try {
                $this->hooks?->fire(HookPoints::INVOICE_CREATED, ['invoiceId' => $invoiceId, 'usernameChangeRequestId' => (int) $r['id']]);
            } catch (Throwable) {
            }

            return [
                'id' => $invoiceId,
                'amount' => $amount,
                'currency_id' => $cols['currency_id'],
                'label' => ($currency['symbol'] ?? '') . number_format($amount, 2),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $r */
    private function settlePaid(array $r): void
    {
        $id = (int) $r['id'];

        if ($this->requests->transition($id, 'awaiting_payment', 'queued', ['paid_at' => UsernameChangeRepository::now()])) {
            $this->requests->event($id, 'paid', 'system', null, null, 'Invoice #' . (int) $r['invoice_id']);
            $this->creditShares($r);
            $this->dispatch($id);

            return;
        }

        // Paid after the request moved on (e.g. staff cancelled it): the money is
        // still ours to account for, so margins are credited regardless.
        if ($r['invoice_id'] !== null) {
            $this->creditShares($r);
        }
    }

    /** @param array<string, mixed> $r */
    private function creditShares(array $r): void
    {
        if ($this->ledger === null || ($r['reseller_id'] ?? null) === null || ($r['fee_cost'] ?? null) === null) {
            return;
        }

        if (!$this->requests->claimFeeCredit((int) $r['id'])) {
            return;
        }

        try {
            $this->postShares($r, false);
            $this->requests->event((int) $r['id'], 'fee_credited', 'system', null, null);
        } catch (Throwable $e) {
            $this->requests->event((int) $r['id'], 'fee_credit_failed', 'system', null, null, $e->getMessage());
        }
    }

    /**
     * Posts (or reverses) each reseller's share. The split is computed in the
     * catalog currency when the invoice was raised; here it is applied as a
     * proportion of what was actually invoiced, so the ledger converts the real
     * document amount to base exactly as it does for store receipts.
     *
     * @param array<string, mixed> $r
     */
    private function postShares(array $r, bool $reverse): void
    {
        $retail = (float) ($r['fee_retail'] ?? 0);
        $invoiced = (float) ($r['fee_amount'] ?? 0);

        if ($retail <= 0 || $invoiced <= 0 || $this->ledger === null) {
            return;
        }

        $invoice = $this->db->selectOne('SELECT currency_id, currency_rate FROM invoices WHERE id = ?', [(int) $r['invoice_id']]);
        $currencyId = ($invoice['currency_id'] ?? null) === null ? null : (int) $invoice['currency_id'];
        $rate = (float) ($invoice['currency_rate'] ?? 1.0);
        $ratio = $invoiced / $retail;
        $label = 'Username change #' . (int) $r['id'] . ' (' . $r['old_username'] . ' → ' . $r['new_username'] . ')';

        $storeMargin = $retail - (float) $r['fee_cost'];
        $shares = [];

        if ($storeMargin >= 0.01) {
            $shares[] = [(int) $r['reseller_id'], 'adjustment', $storeMargin * $ratio, $label . ' — your resale margin'];
        }

        if (($r['fee_upline_cost'] ?? null) !== null) {
            $uplineMargin = (float) $r['fee_cost'] - (float) $r['fee_upline_cost'];
            $upline = $this->db->selectOne(
                'SELECT u.id FROM resellers s JOIN clients c ON c.id = s.client_id JOIN resellers u ON u.id = c.reseller_id WHERE s.id = ? AND u.id <> s.id LIMIT 1',
                [(int) $r['reseller_id']]
            );

            if ($uplineMargin >= 0.01 && $upline !== null) {
                $shares[] = [(int) $upline['id'], 'upline_margin', $uplineMargin * $ratio, $label . ' — sub-reseller sale at reseller ID ' . (int) $r['reseller_id']];
            }
        }

        foreach ($shares as [$resellerId, $kind, $amount, $description]) {
            if ($reverse) {
                $this->ledger->reverseFeeShare($resellerId, $kind, round($amount, 2), $currencyId, $rate, (int) $r['invoice_id'], 'Refund: ' . $description);
            } else {
                $this->ledger->creditFeeShare($resellerId, $kind, round($amount, 2), $currencyId, $rate, (int) $r['invoice_id'], $description);
            }
        }
    }

    /** @param array<string, mixed> $r */
    private function cancelInvoice(array $r): void
    {
        if (($r['invoice_id'] ?? null) === null || $this->invoices === null) {
            return;
        }

        try {
            $invoice = $this->invoices->find((int) $r['invoice_id']);

            if ($invoice !== null && (string) $invoice['status'] === 'unpaid') {
                $this->invoices->markCancelled((int) $r['invoice_id']);
            }
        } catch (Throwable) {
        }
    }

    /** @param array<string, mixed> $r */
    private function storeMayDecide(array $r): bool
    {
        $policy = $this->policies->forService((int) $r['service_id']);

        return ($policy['approval'] ?? null) === 'reseller';
    }

    private function pinMatches(int $clientId, string $pin): bool
    {
        $row = $this->db->selectOne('SELECT security_pin_hash FROM clients WHERE id = ?', [$clientId]);
        $hash = (string) ($row['security_pin_hash'] ?? '');

        return $hash !== '' && $pin !== '' && password_verify($pin, $hash);
    }

    /** @return array{ok: false, message: string, code: string} */
    private static function fail(string $message, string $code = 'error'): array
    {
        return ['ok' => false, 'message' => $message, 'code' => $code];
    }
}
