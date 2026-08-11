<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Catalog\BillingCycle;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use DateTimeImmutable;

/**
 * Orders a recurring add-on onto an existing parent service.
 *
 * The add-on is created as a CHILD services row (services.parent_id =
 * parent service id). That single decision is what makes the rest of the
 * platform work for add-ons for free: the recurring billing job sweeps the
 * child row on its own next_due_date/amount, dunning/suspension act on it
 * like any other service, and cancelling it doesn't touch the parent.
 *
 * The first invoice covers setup fee + the first billing period (the same
 * shape CheckoutService produces for a new order); RecurringBillingService
 * handles every later period. Because the child service starts with
 * next_due_date = today + cycle, there is no double-billing window: the
 * manual first invoice is for the period that ends at that next_due_date,
 * and the recurring job only fires from then on.
 *
 * Add-ons are deliberately billable-only — they attach to an already
 * provisioned parent and have no provisioning module of their own.
 */
final class ServiceAddonService
{
    public function __construct(
        private readonly ServiceRepository $services,
        private readonly ClientRepository $clients,
        private readonly TaxCalculator $tax,
        private readonly CurrencyService $currency,
        private readonly Database $db,
        private readonly HookDispatcher $hooks
    ) {
    }

    /**
     * @return array{success: bool, addonServiceId?: int, invoiceId?: int, amount?: float, setupFee?: float, error?: string}
     */
    public function orderAddon(int $parentServiceId, int $addonProductId, string $addonName, float $price, float $setupFee, string $billingCycle): array
    {
        $parent = $this->services->find($parentServiceId);

        if ($parent === null) {
            return ['success' => false, 'error' => 'Service not found.'];
        }

        if ($parent['status'] !== 'active') {
            return ['success' => false, 'error' => 'Add-ons can only be ordered on an active service.'];
        }

        // The price comes from the add-on product's own product_pricing row
        // (raw, unconverted — the same "fresh catalog price" case
        // CheckoutService converts at order creation and ProrationService
        // converts in upgrade()). Convert it once, before it's written to
        // services.amount or an invoice, so the stored amount is final and
        // in the client's own currency.
        $client = $this->clients->find((int) $parent['client_id']);
        $currency = $this->currency->resolveForClient($client);
        $rate = $this->currency->rateFor($currency);
        $convertedAmount = $this->currency->convert($price, $rate);
        $convertedSetup = $this->currency->convert($setupFee, $rate);

        $today = (new DateTimeImmutable())->format('Y-m-d');

        $addonServiceId = $this->services->create([
            'client_id' => (int) $parent['client_id'],
            'parent_id' => $parentServiceId,
            'product_id' => $addonProductId,
            'product_name' => $addonName,
            'billing_cycle' => $billingCycle,
            'amount' => $convertedAmount,
            'status' => 'active',
            'next_due_date' => ServiceRepository::nextCycleDate($today, $billingCycle),
        ]);

        // First invoice = setup fee + first period, due immediately. Linked
        // to the ADD-ON service (not the parent) so the charge appears under
        // the add-on and is cancelled if the add-on is removed before payment.
        $firstPeriod = $convertedAmount + $convertedSetup;
        $invoiceId = $this->createFirstInvoice($parent, $addonName, $firstPeriod, $billingCycle, $addonServiceId);

        $this->hooks->fire(HookPoints::INVOICE_CREATED, ['invoiceId' => $invoiceId, 'serviceId' => $addonServiceId]);

        return [
            'success' => true,
            'addonServiceId' => $addonServiceId,
            'invoiceId' => $invoiceId,
            'amount' => $convertedAmount,
            'setupFee' => $convertedSetup,
        ];
    }

    /**
     * @param array<string, mixed> $parent
     */
    private function createFirstInvoice(array $parent, string $addonName, float $amount, string $billingCycle, int $addonServiceId): int
    {
        $client = $this->clients->find((int) $parent['client_id']);
        $tax = $this->tax->calculate($client ?? [], $amount);
        $total = $amount + $tax['amount'];
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $currencyLock = $this->currency->denominateFor($client);
        $cycleLabel = BillingCycle::labels()[$billingCycle] ?? $billingCycle;

        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, service_id, status, subtotal, tax_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$parent['client_id'], $addonServiceId, 'unpaid', $amount, $tax['amount'], $total, $currencyLock['currency_id'], $currencyLock['currency_rate'], (new DateTimeImmutable())->format('Y-m-d'), $now, $now]
        );

        $identifier = ServiceRepository::invoiceIdentifierSuffix($parent['domain'] ?? null, $parent['hostname'] ?? null);

        $this->db->insert(
            'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
            [$invoiceId, "{$addonName} ({$cycleLabel}) — Add-on first period{$identifier}", $amount]
        );

        if ($tax['amount'] > 0) {
            $this->db->insert(
                'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
                [$invoiceId, "{$tax['name']} ({$tax['rate']}%)", $tax['amount']]
            );
        }

        return $invoiceId;
    }
}
