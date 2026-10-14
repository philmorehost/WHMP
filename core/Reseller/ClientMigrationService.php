<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Clients\ClientRepository;
use CodeVault\Database;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\TicketRepository;
use CodeVault\Support\TicketService;
use RuntimeException;
use Throwable;

/**
 * Moving a client between providers: reseller store to reseller store, store to the
 * platform, or platform to a store.
 *
 * WHAT MOVES — "everything about the client"
 *
 *   clients.reseller_id   the owner. This one column is what makes the account the new
 *                         provider's: their services, domains and invoices all hang off
 *                         the CLIENT (none of those tables names a store), so the client
 *                         area, every email about them, and where they may sign in
 *                         (ClientSiteAccess) all follow it at once.
 *   tickets.reseller_id   the support history, so the new provider sees every past
 *                         conversation on their desk. This deliberately overrides the
 *                         note in migration 0201 ("old tickets stay with the old store"):
 *                         a move is now an explicit, audited decision to hand the whole
 *                         relationship over, not a silent re-attribution. Guest tickets
 *                         the client sent from the same address to the same store move
 *                         too — they are the same person's history.
 *
 * WHAT DOES NOT MOVE — and why it must not
 *
 *   orders.reseller_id and everything that hangs off it: the store ledger, cost
 *   invoices, payouts and statements. A store's earnings are keyed on the ORDER
 *   (ResellerLedgerRepository::storeOrderForInvoice), and so are its cost bills
 *   (ResellerCostRepository) and a refund's reversal. Re-pointing old orders would
 *   credit the new store with money the old one earned, bill it for sales it never
 *   made, and send a later refund's reversal to the wrong account — rewriting issued
 *   statements in the process. So history stays where it was earned; from the moment
 *   of the move, NEW orders belong to the new provider because checkout attributes
 *   them from the client's store.
 *
 * WHO MAY DO WHAT
 *
 *   Ask:     the client (from their client area, which opens a support ticket), or a
 *            reseller (to bring a customer in, or to release one of theirs).
 *   Decide:  a super admin only. Moving a customer between businesses is not a support
 *            reply; it is a decision with commercial consequences for two resellers.
 *
 * ISOLATION
 *
 * The old store's desk sees that its customer asked to move, but never where to — the
 * destination is kept on the request row, which only platform staff can read. A
 * reseller asking to bring a customer in is never told whether that address has an
 * account anywhere; their answer is the same either way.
 */
final class ClientMigrationService
{
    public const TARGET_PLATFORM = 'platform';
    public const TARGET_STORE = 'store';

    public function __construct(
        private readonly Database $db,
        private readonly ClientMigrationRepository $migrations,
        private readonly ResellerStoreRepository $stores,
        private readonly ResellerStoreLocator $locator,
        private readonly ClientRepository $clients,
        private readonly TicketService $ticketService,
        private readonly TicketRepository $tickets,
        private readonly EmailDispatcher $mail,
        private readonly ?SettingsRepository $settings = null
    ) {
    }

    // ----------------------------------------------------------- destinations ---

    /**
     * A website address, as a customer would type it, to a provider on this platform.
     *
     * Accepts "acme.com", "https://www.acme.com/store", or a platform subdomain. Only a
     * host this platform already SERVES counts — the same rule that decides which store
     * a web request is for (ResellerStoreLocator::resolve), so an unverified domain
     * claim can never be a destination.
     *
     * @return array{ok: bool, target?: string, store?: array<string, mixed>|null, error?: string}
     */
    public function resolveTarget(string $input): array
    {
        $input = trim($input);

        if ($input === '') {
            return ['ok' => false, 'error' => 'Enter the website address of the provider you want to move to.'];
        }

        $host = (string) (parse_url(str_contains($input, '://') ? $input : 'https://' . $input, PHP_URL_HOST) ?: '');
        $host = ResellerStoreLocator::normaliseHost($host);

        if ($host === '') {
            return ['ok' => false, 'error' => 'That does not look like a website address.'];
        }

        $platform = $this->locator->platformHost();

        if ($host === $platform || $host === 'www.' . $platform) {
            return ['ok' => true, 'target' => self::TARGET_PLATFORM, 'store' => null];
        }

        $match = $this->locator->resolve($host);

        if ($match === null || (string) ($match['store']['status'] ?? '') !== 'active') {
            return ['ok' => false, 'error' => 'We could not find a provider at that address on this platform. Check the website address and try again.'];
        }

        return ['ok' => true, 'target' => self::TARGET_STORE, 'store' => $match['store']];
    }

    /**
     * Every destination an admin may choose, platform first.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function destinations(): array
    {
        $options = [['value' => self::TARGET_PLATFORM, 'label' => $this->platformLabel()]];

        foreach ($this->stores->all() as $store) {
            $label = $this->storeLabel($store);

            if ((string) ($store['status'] ?? '') !== 'active') {
                $label .= ' — suspended';
            }

            $options[] = ['value' => (string) (int) $store['id'], 'label' => $label];
        }

        return $options;
    }

    /**
     * "platform" or a store id, as posted by the admin form, to a target pair.
     *
     * @return array{0: string, 1: int|null}
     */
    public static function parseDestination(string $value): array
    {
        $value = trim($value);

        if ($value === self::TARGET_PLATFORM || $value === '') {
            return [self::TARGET_PLATFORM, null];
        }

        $id = (int) $value;

        return $id > 0 ? [self::TARGET_STORE, $id] : [self::TARGET_PLATFORM, null];
    }

    // ---------------------------------------------------------------- preview ---

    /**
     * What moving this client would do, without doing it — the page an admin reads
     * before pressing the button, and the same checks migrate() repeats inside its
     * transaction.
     *
     * @return array{
     *     ok: bool, error: ?string,
     *     client?: array<string, mixed>, from?: array<string, mixed>|null, to?: array<string, mixed>|null,
     *     fromLabel?: string, toLabel?: string, counts?: array<string, int>
     * }
     */
    public function preview(int $clientId, string $target, ?int $toStoreId): array
    {
        $client = $clientId > 0 ? $this->clients->find($clientId) : null;

        if ($client === null) {
            return ['ok' => false, 'error' => 'That client does not exist.'];
        }

        $to = null;

        if ($target === self::TARGET_STORE) {
            $to = $toStoreId !== null ? $this->stores->find($toStoreId) : null;

            if ($to === null) {
                return ['ok' => false, 'error' => 'That store does not exist.'];
            }

            if ((string) ($to['status'] ?? '') !== 'active') {
                return ['ok' => false, 'error' => 'That store is suspended. Reactivate it before moving customers to it.'];
            }
        }

        $fromId = (int) ($client['reseller_id'] ?? 0) ?: null;
        $from = $fromId !== null ? $this->stores->find($fromId) : null;
        $toId = $to !== null ? (int) $to['id'] : null;

        if ($fromId === $toId) {
            return ['ok' => false, 'error' => 'This client is already with that provider.'];
        }

        // A store's OWNER buys from us. Making them another store's customer would put
        // their own reseller programme — their payouts, their statements — behind a
        // competitor's sign-in.
        $ownStore = $this->stores->forClient($clientId);

        if ($ownStore !== null && $to !== null) {
            return ['ok' => false, 'error' => 'This client runs a reseller store (' . $this->storeLabel($ownStore) . '). A store owner buys from the platform and must stay a platform client.'];
        }

        return [
            'ok' => true,
            'error' => null,
            'client' => $client,
            'from' => $from,
            'to' => $to,
            'fromLabel' => $from !== null ? $this->storeLabel($from) : $this->platformLabel(),
            'toLabel' => $to !== null ? $this->storeLabel($to) : $this->platformLabel(),
            'counts' => $this->counts($client, $fromId),
        ];
    }

    // ---------------------------------------------------------------- migrate ---

    /**
     * Move the client. Super-admin authorisation is the CONTROLLER's job; this method
     * trusts its caller on that and guarantees everything else.
     *
     * @return array{success: bool, error: ?string, migrationId?: int}
     */
    public function migrate(
        int $clientId,
        string $target,
        ?int $toStoreId,
        int $adminId,
        ?int $requestId = null,
        string $note = ''
    ): array {
        $preview = $this->preview($clientId, $target, $toStoreId);

        if (!$preview['ok']) {
            return ['success' => false, 'error' => $preview['error']];
        }

        $request = null;

        if ($requestId !== null) {
            $request = $this->migrations->find($requestId);

            if ($request === null || (string) $request['status'] !== 'pending') {
                return ['success' => false, 'error' => 'That request has already been decided.'];
            }
        }

        $client = $preview['client'];
        $from = $preview['from'];
        $to = $preview['to'];
        $fromId = $from !== null ? (int) $from['id'] : null;
        $toId = $to !== null ? (int) $to['id'] : null;
        $note = trim($note);

        try {
            $migrationId = $this->db->transaction(function () use ($client, $fromId, $toId, $target, $adminId, $request, $note, $preview): int {
                // Re-read the owner UNDER A LOCK. The preview was taken outside the
                // transaction; a checkout claiming the account in between would make
                // this move start from an owner the admin never saw.
                $locked = $this->db->selectOne('SELECT id, reseller_id, email FROM clients WHERE id = ? FOR UPDATE', [(int) $client['id']]);
                $lockedOwner = (int) ($locked['reseller_id'] ?? 0) ?: null;

                if ($locked === null || $lockedOwner !== $fromId) {
                    throw new RuntimeException('The client changed provider while you were looking. Review the move again.');
                }

                $now = date('Y-m-d H:i:s');

                $this->db->update('UPDATE clients SET reseller_id = ?, updated_at = ? WHERE id = ?', [$toId, $now, (int) $client['id']]);

                // The support history. To the platform, an escalation means nothing (it
                // is a store asking us), so it is cleared; to another store it is kept,
                // because the question it asked is still open.
                $ticketWhere = 'WHERE client_id = ? OR (client_id IS NULL AND email = ? AND reseller_id <=> ?)';
                $ticketArgs = [(int) $client['id'], strtolower((string) $locked['email']), $fromId];

                $movedTickets = $toId === null
                    ? $this->db->update('UPDATE tickets SET reseller_id = NULL, escalated_at = NULL, escalated_note = NULL, updated_at = ? ' . $ticketWhere, array_merge([$now], $ticketArgs))
                    : $this->db->update('UPDATE tickets SET reseller_id = ?, updated_at = ? ' . $ticketWhere, array_merge([$toId, $now], $ticketArgs));

                $summary = json_encode($preview['counts'] + ['tickets_moved' => $movedTickets], JSON_UNESCAPED_SLASHES) ?: null;

                $fields = [
                    'client_id' => (int) $client['id'],
                    'from_reseller_id' => $fromId,
                    'from_label' => $preview['fromLabel'],
                    'target' => $target,
                    'to_reseller_id' => $toId,
                    'target_label' => $preview['toLabel'],
                    'summary' => $summary,
                ];

                if ($request !== null) {
                    // Conditional on still being pending: a second admin pressing the
                    // same button gets "already decided" and the whole move rolls back.
                    if (!$this->migrations->decide((int) $request['id'], 'completed', $adminId, $note !== '' ? $note : null, $fields)) {
                        throw new RuntimeException('That request has already been decided.');
                    }

                    return (int) $request['id'];
                }

                return $this->migrations->create($fields + [
                    'client_email' => (string) $locked['email'],
                    'requested_by' => 'admin',
                    'status' => 'completed',
                    'admin_id' => $adminId,
                    'decision_note' => $note !== '' ? $note : null,
                    'decided_at' => $now,
                ]);
            });
        } catch (RuntimeException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        // Everything below is notification: best effort, after the commit, and never
        // able to undo a move that has already happened.
        $this->notifyMoved((int) $migrationId, $client, $from, $to, $request, $adminId);

        return ['success' => true, 'error' => null, 'migrationId' => (int) $migrationId];
    }

    /**
     * Decline a request, telling whoever asked on their ticket.
     *
     * @return array{success: bool, error: ?string}
     */
    public function reject(int $requestId, int $adminId, string $adminName, string $note): array
    {
        $request = $this->migrations->find($requestId);

        if ($request === null || (string) $request['status'] !== 'pending') {
            return ['success' => false, 'error' => 'That request has already been decided.'];
        }

        $note = trim($note);

        if (!$this->migrations->decide($requestId, 'rejected', $adminId, $note !== '' ? $note : null)) {
            return ['success' => false, 'error' => 'That request has already been decided.'];
        }

        if (!empty($request['ticket_id'])) {
            $message = 'We are unable to move this account at the moment.'
                . ($note !== '' ? "\n\n" . $note : '')
                . "\n\nReply to this ticket if you have any questions.";

            $this->replyQuietly((int) $request['ticket_id'], $adminId, $this->signatureForTicket((int) $request['ticket_id'], $adminName), $message);
        }

        return ['success' => true, 'error' => null];
    }

    // --------------------------------------------------------------- requests ---

    /**
     * A client asks to move their own account. Opens a support ticket and hands it
     * straight to the platform (escalated, when the client belongs to a store), because
     * only the platform can act on it.
     *
     * @param array<string, mixed> $client the signed-in client
     * @return array{success: bool, error: ?string, ticketId?: int}
     */
    public function requestByClient(array $client, string $targetInput, string $reason): array
    {
        $clientId = (int) ($client['id'] ?? 0);
        $client = $clientId > 0 ? $this->clients->find($clientId) : null;

        if ($client === null) {
            return ['success' => false, 'error' => 'Please sign in again.'];
        }

        $resolved = $this->resolveTarget($targetInput);

        if (!$resolved['ok']) {
            return ['success' => false, 'error' => (string) $resolved['error']];
        }

        $fromId = (int) ($client['reseller_id'] ?? 0) ?: null;
        $to = $resolved['store'] ?? null;
        $toId = $to !== null ? (int) $to['id'] : null;

        if ($fromId === $toId) {
            return ['success' => false, 'error' => 'Your account is already with that provider.'];
        }

        if ($to !== null && $this->stores->forClient($clientId) !== null) {
            return ['success' => false, 'error' => 'Your account runs a reseller store, so it cannot be moved to another provider. Please contact support.'];
        }

        if ($this->migrations->pendingForClient($clientId) !== null) {
            return ['success' => false, 'error' => 'You already have an account move request waiting for review. We will update your ticket as soon as it has been decided.'];
        }

        $from = $fromId !== null ? $this->stores->find($fromId) : null;

        $requestId = $this->migrations->create([
            'client_id' => $clientId,
            'client_email' => (string) $client['email'],
            'from_reseller_id' => $fromId,
            'from_label' => $from !== null ? $this->storeLabel($from) : $this->platformLabel(),
            'target' => (string) $resolved['target'],
            'to_reseller_id' => $toId,
            'target_label' => $to !== null ? $this->storeLabel($to) : $this->platformLabel(),
            'target_input' => mb_substr(trim($targetInput), 0, 255),
            'requested_by' => 'client',
            'requester_client_id' => $clientId,
            'reason' => $this->cleanReason($reason),
        ]);

        // The ticket says THAT the customer wants to move, never WHERE: it sits on the
        // current store's desk, and the destination is another business.
        $message = 'I would like to move my account (including my services, domains, invoices and support history) to another provider.'
            . "\n\nAccount move request #" . $requestId . '.'
            . ($this->cleanReason($reason) !== null ? "\n\nReason: " . $this->cleanReason($reason) : '');

        $ticketId = $this->openTicket(
            $clientId,
            (string) $client['email'],
            'Account move request #' . $requestId,
            trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? '')) ?: (string) $client['email'],
            $message
        );

        if ($ticketId !== null) {
            $this->migrations->attachTicket($requestId, $ticketId);
            $this->escalateIfStoreTicket($ticketId, 'Account move request #' . $requestId . ' — only the platform can action this. Please leave it with us.');
        }

        return ['success' => true, 'error' => null, 'ticketId' => $ticketId ?? 0];
    }

    /**
     * A reseller asks the platform to move a customer — INTO their store ('in'), or one
     * of their own customers OUT to another provider ('out').
     *
     * 'in' gives the same answer whether or not the address has an account anywhere:
     * telling a reseller "that person is somebody else's customer" would turn this form
     * into a way of reading other stores' customer lists.
     *
     * @param array<string, mixed> $owner the signed-in reseller (a platform client)
     * @param array<string, mixed> $store their store
     * @return array{success: bool, error: ?string, ticketId?: int}
     */
    public function requestByReseller(array $owner, array $store, string $direction, string $clientEmail, string $targetInput, string $reason): array
    {
        $email = strtolower(trim($clientEmail));
        $storeId = (int) $store['id'];
        $ownerId = (int) $owner['id'];

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['success' => false, 'error' => "Enter the customer's account email address."];
        }

        if ($email === strtolower((string) ($owner['email'] ?? ''))) {
            return ['success' => false, 'error' => 'That is your own account. A store owner stays a platform client.'];
        }

        $client = $this->clients->findByEmail($email);

        if ($direction === 'in') {
            if ($client !== null && (int) ($client['reseller_id'] ?? 0) === $storeId) {
                return ['success' => false, 'error' => 'That customer is already on your store.'];
            }

            $pending = $client !== null
                ? $this->migrations->pendingForClient((int) $client['id'])
                : $this->migrations->pendingForEmail($email);

            if ($pending !== null) {
                // Already in the queue (whoever asked): one request is enough, and the
                // answer must not differ from a fresh submission.
                return ['success' => true, 'error' => null, 'ticketId' => 0];
            }

            $fromId = $client !== null ? ((int) ($client['reseller_id'] ?? 0) ?: null) : null;
            $from = $fromId !== null ? $this->stores->find($fromId) : null;

            $fields = [
                'client_id' => $client !== null ? (int) $client['id'] : null,
                'client_email' => $email,
                'from_reseller_id' => $fromId,
                'from_label' => $client === null ? null : ($from !== null ? $this->storeLabel($from) : $this->platformLabel()),
                'target' => self::TARGET_STORE,
                'to_reseller_id' => $storeId,
                'target_label' => $this->storeLabel($store),
                'target_input' => null,
            ];
            $summaryLine = 'Please move the customer account ' . $email . ' to my store, ' . $this->storeLabel($store) . '.';
        } elseif ($direction === 'out') {
            if ($client === null || (int) ($client['reseller_id'] ?? 0) !== $storeId) {
                return ['success' => false, 'error' => 'That email address is not a customer of your store.'];
            }

            $resolved = $this->resolveTarget($targetInput);

            if (!$resolved['ok']) {
                return ['success' => false, 'error' => (string) $resolved['error']];
            }

            $to = $resolved['store'] ?? null;

            if ($to !== null && (int) $to['id'] === $storeId) {
                return ['success' => false, 'error' => 'That address is your own store.'];
            }

            if ($this->migrations->pendingForClient((int) $client['id']) !== null) {
                return ['success' => false, 'error' => 'A move for that customer is already waiting for review.'];
            }

            $fields = [
                'client_id' => (int) $client['id'],
                'client_email' => $email,
                'from_reseller_id' => $storeId,
                'from_label' => $this->storeLabel($store),
                'target' => (string) $resolved['target'],
                'to_reseller_id' => $to !== null ? (int) $to['id'] : null,
                'target_label' => $to !== null ? $this->storeLabel($to) : $this->platformLabel(),
                'target_input' => mb_substr(trim($targetInput), 0, 255),
            ];
            $summaryLine = 'Please move my customer ' . $email . ' to the provider at ' . trim($targetInput) . '.';
        } else {
            return ['success' => false, 'error' => 'Choose whether the customer is moving to or from your store.'];
        }

        $requestId = $this->migrations->create($fields + [
            'requested_by' => 'reseller',
            'requester_client_id' => $ownerId,
            'reason' => $this->cleanReason($reason),
        ]);

        // The reseller is OUR client, so this ticket is on the platform's own desk.
        $ticketId = $this->openTicket(
            $ownerId,
            (string) $owner['email'],
            'Account move request #' . $requestId . ': ' . $email,
            trim((string) ($owner['first_name'] ?? '') . ' ' . (string) ($owner['last_name'] ?? '')) ?: (string) $owner['email'],
            $summaryLine
                . "\n\nAccount move request #" . $requestId . ' (from the reseller area).'
                . ($this->cleanReason($reason) !== null ? "\n\nReason: " . $this->cleanReason($reason) : '')
        );

        if ($ticketId !== null) {
            $this->migrations->attachTicket($requestId, $ticketId);
        }

        return ['success' => true, 'error' => null, 'ticketId' => $ticketId ?? 0];
    }

    // ------------------------------------------------------------------ labels ---

    /** @param array<string, mixed> $store */
    public function storeLabel(array $store): string
    {
        return ResellerMailIdentity::displayName($store) . ' (' . $this->locator->hostFor($store) . ')';
    }

    public function platformLabel(): string
    {
        $name = trim((string) ($this->settings?->get('theme.brand_name', '') ?? ''));

        return ($name !== '' ? $name : 'Platform') . ' (main site)';
    }

    // ------------------------------------------------------------- internals ---

    /**
     * What the client has, for the preview and the audit row.
     *
     * @param array<string, mixed> $client
     * @return array<string, int>
     */
    private function counts(array $client, ?int $fromId): array
    {
        $id = (int) $client['id'];
        $count = fn (string $sql, array $args): int => (int) ($this->db->selectOne($sql, $args)['n'] ?? 0);

        return [
            'services' => $count('SELECT COUNT(*) AS n FROM services WHERE client_id = ?', [$id]),
            'domains' => $count('SELECT COUNT(*) AS n FROM domains WHERE client_id = ?', [$id]),
            'invoices' => $count('SELECT COUNT(*) AS n FROM invoices WHERE client_id = ?', [$id]),
            'unpaid_invoices' => $count("SELECT COUNT(*) AS n FROM invoices WHERE client_id = ? AND status = 'unpaid'", [$id]),
            'tickets' => $count(
                'SELECT COUNT(*) AS n FROM tickets WHERE client_id = ? OR (client_id IS NULL AND email = ? AND reseller_id <=> ?)',
                [$id, strtolower((string) $client['email']), $fromId]
            ),
            'orders' => $count('SELECT COUNT(*) AS n FROM orders WHERE client_id = ?', [$id]),
        ];
    }

    /**
     * @param array<string, mixed>      $client
     * @param array<string, mixed>|null $from
     * @param array<string, mixed>|null $to
     * @param array<string, mixed>|null $request
     */
    private function notifyMoved(int $migrationId, array $client, ?array $from, ?array $to, ?array $request, int $adminId): void
    {
        $clientId = (int) $client['id'];
        $loginUrl = ($to !== null ? $this->locator->baseUrlFor($to) : $this->locator->platformScheme() . '://' . $this->locator->platformHost()) . '/client/login';
        $newName = $to !== null ? ResellerMailIdentity::displayName($to) : $this->platformName();
        $requestedBy = (string) ($request['requested_by'] ?? 'admin');
        $requesterId = (int) ($request['requester_client_id'] ?? 0);
        $ticketId = (int) ($request['ticket_id'] ?? 0);

        // The client hears from their NEW provider. On a request that came from their
        // own ticket, the answer goes on that ticket (which is now on the new store's
        // desk, so the hook brands it as the new store) instead of a second email.
        if ($requestedBy === 'client' && $ticketId > 0) {
            $this->replyQuietly(
                $ticketId,
                $adminId > 0 ? $adminId : null,
                $newName,
                'Your account has been moved to ' . $newName . ", together with your services, domains, invoices and support history.\n\n"
                    . 'From now on, please sign in at ' . $loginUrl . ' with your usual email address and password.'
            );
        } else {
            $mailer = $to !== null ? $this->mail->onBehalfOfStore($to) : $this->mail->onBehalfOfPlatform();
            $this->sendQuietly(fn () => $mailer->sendTemplate('client_account_moved', (string) $client['email'], [
                'first_name' => (string) ($client['first_name'] ?? ''),
                'login_url' => $loginUrl,
                'company_name' => $newName,
            ], $clientId));
        }

        if ($requestedBy === 'reseller' && $ticketId > 0) {
            $this->replyQuietly(
                $ticketId,
                $adminId > 0 ? $adminId : null,
                $this->platformName(),
                'Done — the account ' . (string) $client['email'] . ' has been moved as requested (move #' . $migrationId . ').'
            );
        }

        // Both store owners are OUR clients, so these are platform mail.
        foreach ([['reseller_client_moved_out', $from], ['reseller_client_moved_in', $to]] as [$template, $store]) {
            if ($store === null) {
                continue;
            }

            $owner = $this->clients->find((int) $store['client_id']);

            if ($owner === null || ($requestedBy === 'reseller' && (int) $owner['id'] === $requesterId)) {
                continue;
            }

            $this->sendQuietly(fn () => $this->mail->onBehalfOfPlatform()->sendTemplate($template, (string) $owner['email'], [
                'first_name' => (string) ($owner['first_name'] ?? ''),
                'client_email' => (string) $client['email'],
                'store_name' => ResellerMailIdentity::displayName($store),
                'company_name' => $this->platformName(),
            ], (int) $owner['id']));
        }
    }

    /** Open a ticket in the default department; null when there is no department at all. */
    private function openTicket(int $clientId, string $email, string $subject, string $authorName, string $message): ?int
    {
        $department = $this->db->selectOne('SELECT id FROM departments ORDER BY id ASC LIMIT 1');

        if ($department === null) {
            return null;
        }

        return $this->ticketService->open($clientId, $email, (int) $department['id'], $subject, $authorName, $message);
    }

    /** Hand a store's ticket to the platform, the same mark a store's own escalation sets. */
    private function escalateIfStoreTicket(int $ticketId, string $note): void
    {
        $ticket = $this->tickets->find($ticketId);
        $storeId = (int) ($ticket['reseller_id'] ?? 0);

        if ($storeId > 0) {
            $this->tickets->escalateForReseller($ticketId, $storeId, $note);
        }
    }

    /**
     * Who signs a decision on a ticket: the store that owns the ticket (the customer
     * must keep hearing from them), else us.
     */
    private function signatureForTicket(int $ticketId, string $fallback): string
    {
        $ticket = $this->tickets->find($ticketId);
        $store = !empty($ticket['reseller_id']) ? $this->stores->find((int) $ticket['reseller_id']) : null;

        return $store !== null ? ResellerMailIdentity::displayName($store) : ($fallback !== '' ? $fallback : $this->platformName());
    }

    private function replyQuietly(int $ticketId, ?int $adminId, string $authorName, string $message): void
    {
        try {
            $this->ticketService->reply($ticketId, 'admin', $adminId, $authorName, $message);
        } catch (Throwable) {
            // The move or decision is already recorded; a failed notification must not
            // look like a failed move.
        }
    }

    private function sendQuietly(callable $send): void
    {
        try {
            $send();
        } catch (Throwable) {
        }
    }

    private function platformName(): string
    {
        $name = trim((string) ($this->settings?->get('theme.brand_name', '') ?? ''));

        return $name !== '' ? $name : 'Support';
    }

    private function cleanReason(string $reason): ?string
    {
        $reason = trim($reason);

        return $reason === '' ? null : mb_substr($reason, 0, 2000);
    }
}
