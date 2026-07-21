<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;
use CodeVault\Modules\GatewayModule;
use CodeVault\Modules\ModuleManager;

/**
 * Attempts to settle an unpaid invoice automatically by charging the
 * client's saved payment method (R5 auto-charge). This is what turns
 * recurring *invoicing* into recurring *billing*: the cron sweep calls this
 * for each due invoice, and a success records a payment + marks the invoice
 * paid exactly as a manual/redirect payment would (via PaymentService), so
 * receipts, hooks and reconciliation all behave identically. A failure is
 * left untouched for the dunning job to chase.
 */
final class AutoChargeService
{
    public function __construct(
        private readonly PaymentMethodRepository $methods,
        private readonly PaymentGatewayRepository $gateways,
        private readonly TransactionRepository $transactions,
        private readonly PaymentService $payments,
        private readonly ModuleManager $modules,
        private readonly Database $db
    ) {
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $client
     * @return array{charged: bool, reason: string}
     */
    public function attempt(array $invoice, array $client): array
    {
        $invoiceId = (int) $invoice['id'];

        if (($invoice['status'] ?? '') !== 'unpaid') {
            return ['charged' => false, 'reason' => 'not-unpaid'];
        }

        $remaining = round((float) $invoice['total'] - $this->transactions->totalCompletedForInvoice($invoiceId), 2);

        if ($remaining <= 0) {
            return ['charged' => false, 'reason' => 'nothing-due'];
        }

        $method = $this->methods->defaultForClient((int) $client['id']);

        if ($method === null) {
            return ['charged' => false, 'reason' => 'no-saved-method'];
        }

        $slug = (string) $method['gateway_slug'];
        $gatewayRow = $this->gateways->findBySlug($slug);
        $module = $this->modules->get(GatewayModule::class, $slug);

        if ($gatewayRow === null || (int) $gatewayRow['is_enabled'] !== 1 || !$module instanceof GatewayModule) {
            return ['charged' => false, 'reason' => 'gateway-unavailable'];
        }

        $config = json_decode((string) ($gatewayRow['config'] ?? '{}'), true) ?: [];

        // Charge in the gateway's configured currency, converting from the
        // invoice's currency the same way the interactive redirect flow does
        // (PaymentCallbackController::initiate).
        $gatewayAmount = $this->gatewayAmount($remaining, $invoice, $config);

        $reference = "cv-auto-{$slug}-{$invoiceId}-" . bin2hex(random_bytes(6));

        $result = $module->chargeToken([
            'config' => $config,
            'token' => (string) $method['token'],
            'email' => (string) ($client['email'] ?? ''),
            'amount' => $gatewayAmount,
            'reference' => $reference,
            'metadata' => ['invoice_id' => $invoiceId, 'client_id' => (int) $client['id'], 'auto_charge' => true],
        ]);

        if (($result['success'] ?? false) !== true) {
            return ['charged' => false, 'reason' => 'declined: ' . ((string) ($result['message'] ?? 'charge failed'))];
        }

        // Record in the INVOICE's own currency (the amount actually owed) so
        // the invoice reconciles to paid regardless of gateway-currency
        // conversion.
        $this->payments->recordPayment($invoiceId, $slug, $remaining, (string) ($result['transactionId'] ?? $reference));

        return ['charged' => true, 'reason' => 'ok'];
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $config
     */
    private function gatewayAmount(float $remaining, array $invoice, array $config): float
    {
        $gatewayCurrency = strtoupper(trim((string) ($config['gateway_currency'] ?? 'NGN'))) ?: 'NGN';

        $invoiceCurrId = $invoice['currency_id'] ?? null;
        $invoiceCurr = $invoiceCurrId !== null
            ? $this->db->selectOne('SELECT code, exchange_rate FROM currencies WHERE id = ?', [$invoiceCurrId])
            : null;
        $invoiceCode = $invoiceCurr !== null ? (string) $invoiceCurr['code'] : 'USD';
        $invoiceRate = $invoiceCurr !== null ? (float) $invoiceCurr['exchange_rate'] : 1.0000;

        if ($invoiceCode === $gatewayCurrency) {
            return $remaining;
        }

        $gatewayCurr = $this->db->selectOne('SELECT exchange_rate FROM currencies WHERE code = ?', [$gatewayCurrency]);
        $gatewayRate = $gatewayCurr !== null ? (float) $gatewayCurr['exchange_rate'] : 1.0000;

        if ($invoiceRate <= 0.0) {
            $invoiceRate = 1.0000;
        }

        return round($remaining * ($gatewayRate / $invoiceRate), 2);
    }
}
