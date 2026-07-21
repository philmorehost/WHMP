<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Modules\GatewayModule;
use CodeVault\Modules\ModuleManager;

/**
 * Reverses a completed transaction one of two ways: back into the client's
 * account credit ("wallet" — money stays in the system, immediately
 * usable), or out through the original payment gateway ("external" — an
 * actual gateway-side refund, e.g. back to the client's card). Both paths
 * mark the transaction `refunded` and, if the refund covers a paid
 * invoice, flip the invoice to `refunded` too — same status the invoice
 * schema has always supported (see AdminInvoiceController/invoice-show),
 * it just had no admin action that ever set it until now.
 */
final class RefundService
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly TransactionRepository $transactions,
        private readonly ClientCreditRepository $credit,
        private readonly PaymentGatewayRepository $gateways,
        private readonly ModuleManager $modules,
        private readonly HookDispatcher $hooks
    ) {
    }

    /** @return array{success: bool, message: string} */
    public function refundToWallet(int $transactionId, ?float $amount, string $reason, ?int $adminId): array
    {
        [$transaction, $invoice, $error] = $this->resolve($transactionId);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        $refundAmount = $this->clampAmount($amount, (float) $transaction['amount']);

        if ($refundAmount === null) {
            return ['success' => false, 'message' => 'Refund amount must be greater than zero and no more than the original transaction.'];
        }

        $this->credit->add(
            (int) $invoice['client_id'],
            $refundAmount,
            $reason !== '' ? $reason : "Refund for invoice #{$invoice['id']}",
            (int) $invoice['id'],
            $adminId
        );

        $this->finalize($transactionId, $invoice, $refundAmount, 'wallet');

        return ['success' => true, 'message' => sprintf('Refunded $%.2f to the client\'s account credit.', $refundAmount)];
    }

    /** @return array{success: bool, message: string} */
    public function refundViaGateway(int $transactionId, ?float $amount): array
    {
        [$transaction, $invoice, $error] = $this->resolve($transactionId);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        $slug = (string) $transaction['gateway_slug'];
        $module = $this->modules->get(GatewayModule::class, $slug);

        if (!$module instanceof GatewayModule) {
            return ['success' => false, 'message' => "\"{$slug}\" isn't a real payment gateway — use \"Refund to Wallet\" instead."];
        }

        $refundAmount = $this->clampAmount($amount, (float) $transaction['amount']);

        if ($refundAmount === null) {
            return ['success' => false, 'message' => 'Refund amount must be greater than zero and no more than the original transaction.'];
        }

        $gatewayRow = $this->gateways->findBySlug($slug);
        $config = $gatewayRow !== null ? (json_decode((string) ($gatewayRow['config'] ?? '{}'), true) ?: []) : [];

        $result = $module->refund([
            'transactionId' => $transaction['gateway_transaction_id'],
            'amount' => $refundAmount,
            'config' => $config,
        ]);

        if (!($result['success'] ?? false)) {
            return ['success' => false, 'message' => (string) ($result['message'] ?? 'Gateway refund failed.')];
        }

        $this->finalize($transactionId, $invoice, $refundAmount, 'gateway');

        return ['success' => true, 'message' => (string) ($result['message'] ?? sprintf('Refunded $%.2f via %s.', $refundAmount, $slug))];
    }

    /** @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>, 2: ?string} */
    private function resolve(int $transactionId): array
    {
        $transaction = $this->transactions->find($transactionId);

        if ($transaction === null || $transaction['status'] !== 'completed') {
            return [null, null, 'Transaction not found or not eligible for refund.'];
        }

        $invoice = $this->invoices->find((int) $transaction['invoice_id']);

        if ($invoice === null) {
            return [null, null, 'Invoice not found for this transaction.'];
        }

        return [$transaction, $invoice, null];
    }

    private function clampAmount(?float $requested, float $transactionAmount): ?float
    {
        $amount = $requested !== null ? min($requested, $transactionAmount) : $transactionAmount;

        return $amount > 0 ? round($amount, 2) : null;
    }

    /** @param array<string, mixed> $invoice */
    private function finalize(int $transactionId, array $invoice, float $amount, string $method): void
    {
        $this->transactions->markRefunded($transactionId);

        if ($invoice['status'] === 'paid') {
            $this->invoices->markRefunded((int) $invoice['id']);
        }

        $this->hooks->fire(HookPoints::INVOICE_REFUNDED, [
            'invoiceId' => (int) $invoice['id'],
            'amount' => $amount,
            'method' => $method,
        ]);
    }
}
