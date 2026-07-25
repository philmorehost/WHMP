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
        try {
            $transactionId = $this->transactions->create($invoiceId, $gatewaySlug, $amount, 'completed', $gatewayTransactionId);
        } catch (\PDOException $e) {
            // If the unique constraint on (gateway_slug, gateway_transaction_id) is violated,
            // this is a duplicate webhook/callback payload. Silently ignore it to prevent race conditions.
            if ($e->getCode() === '23000') {
                return ['transactionId' => 0, 'invoicePaid' => false];
            }
            throw $e;
        }

        $invoice = $this->invoices->find($invoiceId);
        $totalPaid = $this->transactions->totalCompletedForInvoice($invoiceId);
        $invoicePaid = false;

        // A payment that round-trips through a gateway's own currency (charged as
        // base × rate, recorded back as gateway ÷ rate) can land a fraction of a
        // cent under the total, which would leave a fully-paid invoice unpaid
        // forever. Half a cent of slack absorbs that without letting a genuine
        // underpayment through.
        $isCovered = $invoice !== null
            && round($totalPaid, 2) >= round((float) $invoice['total'], 2) - 0.005;

        if ($invoice !== null && $invoice['status'] === 'unpaid' && $isCovered) {
            $rowsAffected = $this->invoices->markPaid($invoiceId);

            if ($rowsAffected > 0) {
                // Check if it is a deposit invoice
                $db = \CodeVault\Support\App::container()->make(\CodeVault\Database::class);
                $items = $db->select('SELECT * FROM invoice_items WHERE invoice_id = ?', [$invoiceId]);
                $isDeposit = false;
                foreach ($items as $item) {
                    if (stripos((string) $item['description'], 'Add Funds') !== false || stripos((string) $item['description'], 'Deposit') !== false) {
                        $isDeposit = true;
                        break;
                    }
                }

                if ($isDeposit) {
                    $creditRepo = \CodeVault\Support\App::container()->make(\CodeVault\Billing\ClientCreditRepository::class);
                    $creditRepo->add((int) $invoice['client_id'], (float) $invoice['total'], "Wallet deposit: Invoice #{$invoiceId}", $invoiceId);
                }

                $this->hooks->fire(HookPoints::INVOICE_PAID, ['invoiceId' => $invoiceId]);
                $invoicePaid = true;
            }
        }

        $this->hooks->fire(HookPoints::TRANSACTION_ADDED, ['invoiceId' => $invoiceId, 'amount' => $amount, 'gateway' => $gatewaySlug]);

        return ['transactionId' => $transactionId, 'invoicePaid' => $invoicePaid];
    }
}
