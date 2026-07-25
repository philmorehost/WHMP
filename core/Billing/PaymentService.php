<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;
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
    /**
     * $db and $credit are only needed to top up a client's wallet when a
     * deposit invoice settles, and are optional so this service can still be
     * constructed somewhere the application container was never built (unit
     * tests, CLI jobs). They used to be pulled from the global container at
     * the moment a payment settled, which meant recording a covering payment
     * threw outright in any such context — the payment itself needs neither.
     */
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly TransactionRepository $transactions,
        private readonly HookDispatcher $hooks,
        private readonly ?Database $db = null,
        private readonly ?ClientCreditRepository $credit = null
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
                $this->creditWalletIfDeposit($invoiceId, $invoice);

                $this->hooks->fire(HookPoints::INVOICE_PAID, ['invoiceId' => $invoiceId]);
                $invoicePaid = true;
            }
        }

        $this->hooks->fire(HookPoints::TRANSACTION_ADDED, ['invoiceId' => $invoiceId, 'amount' => $amount, 'gateway' => $gatewaySlug]);

        return ['transactionId' => $transactionId, 'invoicePaid' => $invoicePaid];
    }

    /**
     * A settled "Add Funds" invoice becomes wallet credit. The amount is
     * copied straight from the invoice total, which is already a base-currency
     * figure, so the balance stays in the same unit every other stored amount
     * uses (see CurrencyService).
     *
     * Best-effort by design: without the optional collaborators there is
     * nothing to credit against, and a payment must still record correctly
     * rather than fail because a wallet could not be topped up.
     *
     * @param array<string, mixed> $invoice
     */
    private function creditWalletIfDeposit(int $invoiceId, array $invoice): void
    {
        if ($this->db === null || $this->credit === null) {
            return;
        }

        $items = $this->db->select('SELECT description FROM invoice_items WHERE invoice_id = ?', [$invoiceId]);

        foreach ($items as $item) {
            $description = (string) $item['description'];

            if (stripos($description, 'Add Funds') !== false || stripos($description, 'Deposit') !== false) {
                $this->credit->add(
                    (int) $invoice['client_id'],
                    (float) $invoice['total'],
                    "Wallet deposit: Invoice #{$invoiceId}",
                    $invoiceId
                );

                return;
            }
        }
    }
}
