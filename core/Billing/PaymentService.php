<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;

/**
 * The one place a payment (from any gateway — manual confirmation today,
 * a real IPN callback later) turns into a transaction row and, once the
 * invoice total is covered, a paid invoice. Both ManualGateway's admin
 * confirmation and a future GatewayModule::handleCallback() call this.
 */
final class PaymentService
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly TransactionRepository $transactions,
        private readonly HookDispatcher $hooks
    ) {
    }

    /**
     * @return array{transactionId: int, invoicePaid: bool}
     */
    public function recordPayment(int $invoiceId, string $gatewaySlug, float $amount, ?string $gatewayTransactionId = null): array
    {
        $transactionId = $this->transactions->create($invoiceId, $gatewaySlug, $amount, 'completed', $gatewayTransactionId);

        $invoice = $this->invoices->find($invoiceId);
        $totalPaid = $this->transactions->totalCompletedForInvoice($invoiceId);
        $invoicePaid = false;

        if ($invoice !== null && $invoice['status'] === 'unpaid' && $totalPaid >= (float) $invoice['total']) {
            $this->invoices->markPaid($invoiceId);
            $this->hooks->fire(HookPoints::INVOICE_PAID, ['invoiceId' => $invoiceId]);
            $invoicePaid = true;
        }

        $this->hooks->fire(HookPoints::TRANSACTION_ADDED, ['invoiceId' => $invoiceId, 'amount' => $amount, 'gateway' => $gatewaySlug]);

        return ['transactionId' => $transactionId, 'invoicePaid' => $invoicePaid];
    }
}
