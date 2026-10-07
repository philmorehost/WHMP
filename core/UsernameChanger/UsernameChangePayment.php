<?php

declare(strict_types=1);

namespace CodeVault\UsernameChanger;

use CodeVault\Billing\ClientCreditRepository;
use CodeVault\Billing\CreditService;
use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\PaymentGatewayRepository;
use CodeVault\Billing\TransactionRepository;
use CodeVault\Database;
use Throwable;

/**
 * Lets a client pay the username-change fee without leaving the modal (plan §16).
 *
 * The fee is an ordinary invoice (raised once the request is confirmed and, if
 * required, approved), so every way of paying is the platform's own:
 *
 *  - wallet: CreditService::applyToInvoice(), exactly what the invoice page's
 *    "Apply credit" does. Offered only when the balance covers everything still
 *    due — a part-payment would strand money on an invoice that is cancelled if
 *    the request is cancelled. A short named lock stops a double-click paying
 *    twice;
 *  - any enabled gateway: the existing /client/invoices/{id}/pay/{gateway}
 *    (or PayHub's inline popup). The payment callback sends the client back to
 *    the service page (see rememberReturn()).
 *
 * Either way the INVOICE_PAID hook moves the request to the queue — nothing here
 * changes request state directly.
 */
final class UsernameChangePayment
{
    public const RETURN_SESSION_KEY = 'pay_return';

    public function __construct(
        private readonly Database $db,
        private readonly ClientCreditRepository $wallet,
        private readonly CreditService $credit,
        private readonly TransactionRepository $transactions,
        private readonly PaymentGatewayRepository $gateways,
        private readonly CurrencyService $currency
    ) {
    }

    /**
     * Everything the Pay step shows, or null when the request is not waiting on
     * a payment this client can make.
     *
     * @param array<string, mixed> $request username_change_requests row
     * @param array<string, mixed> $client
     * @return array<string, mixed>|null
     */
    public function summary(array $request, array $client): ?array
    {
        if ((string) ($request['status'] ?? '') !== 'awaiting_payment' || ($request['invoice_id'] ?? null) === null) {
            return null;
        }

        $invoice = $this->invoice((int) $request['invoice_id']);

        if ($invoice === null || (int) $invoice['client_id'] !== (int) $client['id']) {
            return null;
        }

        $currencyId = $invoice['currency_id'] === null ? null : (int) $invoice['currency_id'];
        $rate = (float) ($invoice['currency_rate'] ?? 1.0);
        $money = fn (float $base): string => $this->currency->formatLocked($base, $currencyId, $rate);
        $paid = $this->transactions->totalCompletedForInvoice((int) $invoice['id']);
        $due = max(0.0, round((float) $invoice['total'] - $paid, 2));
        $balance = round($this->wallet->balance((int) $client['id']), 2);
        $parent = ($invoice['parent_invoice_id'] ?? null) === null ? null : (int) $invoice['parent_invoice_id'];

        return [
            'request_id' => (int) $request['id'],
            'service_id' => (int) $request['service_id'],
            'invoice_id' => (int) $invoice['id'],
            'invoice_url' => '/client/invoices/' . (int) $invoice['id'],
            'invoice_status' => (string) $invoice['status'],
            // Absorbed into a consolidated invoice: only the parent is payable.
            'pay_invoice_id' => $parent ?? (int) $invoice['id'],
            'consolidated' => $parent !== null,
            'total_label' => $money((float) $invoice['total']),
            'paid_label' => $paid > 0 ? $money($paid) : null,
            'due' => $due,
            'due_label' => $money($due),
            'wallet' => [
                'balance' => $balance,
                'balance_label' => $money($balance),
                'covers' => $parent === null && $due > 0 && $balance + 0.005 >= $due,
                'short_label' => $balance < $due ? $money(round($due - $balance, 2)) : null,
                'add_funds_url' => '/client/wallet/add-funds',
            ],
            'gateways' => $parent === null ? $this->usableGateways() : [],
        ];
    }

    /**
     * Pays the request's invoice from the client's wallet.
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $client
     * @return array{ok: bool, message: string, code?: string, paid?: bool}
     */
    public function payWithWallet(array $request, array $client): array
    {
        $summary = $this->summary($request, $client);

        if ($summary === null) {
            return ['ok' => false, 'message' => 'This request is not waiting for payment.', 'code' => 'not_due'];
        }

        if ($summary['consolidated']) {
            return ['ok' => false, 'message' => 'This fee is part of invoice #' . $summary['pay_invoice_id'] . '. Please pay that invoice.', 'code' => 'consolidated'];
        }

        if (!$summary['wallet']['covers']) {
            return ['ok' => false, 'message' => 'Your wallet balance (' . $summary['wallet']['balance_label'] . ') does not cover ' . $summary['due_label'] . '. Add funds or pay another way.', 'code' => 'insufficient'];
        }

        $invoiceId = (int) $summary['invoice_id'];
        $lock = 'ucn-pay-' . $invoiceId;

        if (!$this->lock($lock)) {
            return ['ok' => false, 'message' => 'A payment for this invoice is already in progress.', 'code' => 'busy'];
        }

        try {
            // Re-read inside the lock: a gateway or another tab may have paid it.
            $invoice = $this->invoice($invoiceId);

            if ($invoice === null || (string) $invoice['status'] !== 'unpaid') {
                return ['ok' => (string) ($invoice['status'] ?? '') === 'paid', 'message' => 'This invoice is already paid.', 'paid' => true];
            }

            $result = $this->credit->applyToInvoice((int) $client['id'], $invoiceId, (float) $summary['due']);

            if (!($result['success'] ?? false)) {
                return ['ok' => false, 'message' => (string) ($result['error'] ?? 'We could not take the payment from your wallet.'), 'code' => 'wallet_failed'];
            }
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'We could not take the payment from your wallet. Nothing was charged — please try again.', 'code' => 'error'];
        } finally {
            $this->unlock($lock);
        }

        $after = $this->invoice($invoiceId);
        $paid = $after !== null && (string) $after['status'] === 'paid';

        return [
            'ok' => true,
            'paid' => $paid,
            'message' => $paid
                ? 'Paid ' . $summary['due_label'] . ' from your wallet. Your username change is on its way.'
                : 'Applied ' . $summary['due_label'] . ' from your wallet.',
        ];
    }

    /**
     * Where a gateway payment for $invoiceId should land afterwards. Read by
     * PaymentCallbackController; anything not a local path is ignored there.
     *
     * @param array<int|string, string>|mixed $current the session's existing map
     * @return array<int, string>
     */
    public static function withReturn(mixed $current, int $invoiceId, string $path): array
    {
        $map = is_array($current) ? $current : [];
        unset($map[$invoiceId]);
        $map[$invoiceId] = $path;

        // Keep the map small: the latest few invoices are all that matter.
        return array_slice($map, -10, null, true);
    }

    /**
     * Enabled gateways a client can actually use: configured ones (a gateway
     * without keys would only show an error) and manual bank transfer.
     *
     * @return array<int, array{slug: string, name: string, manual: bool, inline: bool, details: string}>
     */
    private function usableGateways(): array
    {
        $out = [];

        try {
            $rows = $this->gateways->allEnabled();
        } catch (Throwable) {
            return [];
        }

        foreach ($rows as $g) {
            $slug = (string) ($g['slug'] ?? '');
            $config = json_decode((string) ($g['config'] ?? '{}'), true);
            $config = is_array($config) ? $config : [];

            if ($slug === '' || $slug === 'credit') {
                continue;
            }

            if ($slug === 'manual') {
                $out[] = ['slug' => $slug, 'name' => (string) $g['name'], 'manual' => true, 'inline' => false,
                    'details' => (string) ($config['bank_details'] ?? 'Contact us for bank transfer details.')];

                continue;
            }

            if (empty($config['secret_key']) && empty($config['api_key']) && empty($config['client_id'])) {
                continue;
            }

            $out[] = ['slug' => $slug, 'name' => (string) $g['name'], 'manual' => false,
                'inline' => $slug === 'payhub' && !empty($config['public_key']), 'details' => ''];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function invoice(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT id, client_id, status, total, currency_id, currency_rate, parent_invoice_id FROM invoices WHERE id = ?',
            [$id]
        );
    }

    private function lock(string $name): bool
    {
        try {
            $row = $this->db->selectOne('SELECT GET_LOCK(?, 0) AS l', [$name]);

            return (int) ($row['l'] ?? 0) === 1;
        } catch (Throwable) {
            return true; // no named locks (e.g. SQLite in dev): rely on the re-read
        }
    }

    private function unlock(string $name): void
    {
        try {
            $this->db->selectOne('SELECT RELEASE_LOCK(?) AS l', [$name]);
        } catch (Throwable) {
        }
    }
}
