<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use RuntimeException;

/**
 * Grants and spends account credit (blueprint §4.4). Applying credit to an
 * invoice is treated as a payment (gateway_slug 'credit') so it goes
 * through the exact same "does this cover the total" logic as a real
 * gateway payment. The amount applied is capped at total minus whatever's
 * already been paid (from any gateway) — never the raw invoice total —
 * so credit can't double-count on top of an existing partial payment.
 */
final class CreditService
{
    public function __construct(
        private readonly ClientCreditRepository $credit,
        private readonly InvoiceRepository $invoices,
        private readonly TransactionRepository $transactions,
        private readonly PaymentService $payments,
        private readonly HookDispatcher $hooks
    ) {
    }

    public function grant(int $clientId, float $amount, string $reason, ?int $adminId = null): void
    {
        if ($amount <= 0) {
            throw new RuntimeException('Credit grants must be a positive amount.');
        }

        $this->credit->add($clientId, $amount, $reason, null, $adminId);
    }

    public function debit(int $clientId, float $amount, string $reason, ?int $adminId = null): void
    {
        if ($amount <= 0) {
            throw new RuntimeException('Credit debits must be a positive amount.');
        }

        $this->credit->add($clientId, -$amount, $reason, null, $adminId);
    }

    /**
     * @return array{success: bool, applied?: float, error?: string}
     */
    public function applyToInvoice(int $clientId, int $invoiceId, ?float $amount = null): array
    {
        $invoice = $this->invoices->find($invoiceId);

        if ($invoice === null || (int) $invoice['client_id'] !== $clientId) {
            return ['success' => false, 'error' => 'Invoice not found for this client.'];
        }

        if ($invoice['status'] !== 'unpaid') {
            return ['success' => false, 'error' => 'Invoice is not unpaid.'];
        }

        $balance = $this->credit->balance($clientId);
        $alreadyPaid = $this->transactions->totalCompletedForInvoice($invoiceId);
        $remaining = round((float) $invoice['total'] - $alreadyPaid, 2);
        $applied = min($amount ?? $balance, $balance, $remaining);

        if ($applied <= 0) {
            return ['success' => false, 'error' => 'No credit available to apply.'];
        }

        $this->credit->add($clientId, -$applied, "Applied to invoice #{$invoiceId}", $invoiceId);
        $this->payments->recordPayment($invoiceId, 'credit', $applied);
        $this->hooks->fire(HookPoints::CREDIT_APPLIED, ['clientId' => $clientId, 'invoiceId' => $invoiceId, 'amount' => $applied]);

        return ['success' => true, 'applied' => $applied];
    }
}
