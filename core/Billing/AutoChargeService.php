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
        private readonly Database $db,
        private readonly CurrencyService $currency
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

        // A NULL currency_id means "the base currency" — the convention
        // lockColumns()/denominateFor() write. Reading it as a hardcoded 'USD'
        // (as this did) made an NGN-denominated invoice on an NGN-default
        // install look like a cross-currency charge, so it was multiplied by
        // NGN's own live rate: ₦7,501.50 auto-charged as ₦11,177,235. Same
        // defect prepareCharge() carried; both now resolve the code the same
        // way and let crossConvert() no-op when the codes match.
        $invoiceCurrencyId = ($invoice['currency_id'] ?? null) !== null ? (int) $invoice['currency_id'] : null;
        $invoiceRate = (float) ($invoice['currency_rate'] ?? 1.0);
        $invoiceCode = $this->currency->codeFor($invoiceCurrencyId);

        // Charge what the invoice shows the client, exactly as the interactive
        // flow does (PaymentCallbackController::prepareCharge).
        $shownAmount = round($remaining * ($invoiceCurrencyId !== null && $invoiceRate > 0 ? $invoiceRate : 1.0), 2);

        return $this->currency->crossConvert($shownAmount, $invoiceCode, $gatewayCurrency);
    }
}
