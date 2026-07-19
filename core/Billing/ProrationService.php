<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientRepository;
use CodeVault\Database;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use DateTimeImmutable;
use RuntimeException;

/**
 * Upgrade/Downgrade engine (blueprint §4.4) — changes a service's
 * product/price with an explicit proration mode. This is the
 * highest-bug-risk flow the blueprint calls out (§4.4), because it sits at
 * the intersection of billing, products, and the service's own schedule.
 */
final class ProrationService
{
    public function __construct(
        private readonly ServiceRepository $services,
        private readonly ClientRepository $clients,
        private readonly TaxCalculator $tax,
        private readonly ClientCreditRepository $credit,
        private readonly CreditService $creditService,
        private readonly CurrencyService $currency,
        private readonly Database $db,
        private readonly HookDispatcher $hooks
    ) {
    }

    /**
     * @return array{success: bool, chargeAmount: float, creditAmount: float, invoiceId?: int, error?: string}
     */
    public function upgrade(int $serviceId, int $newProductId, string $newProductName, float $newAmount, string $mode): array
    {
        $service = $this->services->find($serviceId);

        if ($service === null) {
            return ['success' => false, 'chargeAmount' => 0, 'creditAmount' => 0, 'error' => 'Service not found.'];
        }

        if ($service['status'] !== 'active') {
            return ['success' => false, 'chargeAmount' => 0, 'creditAmount' => 0, 'error' => 'Only active services can be upgraded.'];
        }

        $this->hooks->fire(HookPoints::UPGRADE_REQUESTED, ['serviceId' => $serviceId, 'mode' => $mode]);

        $result = match ($mode) {
            ProrationMode::NONE => $this->applyNone($service, $newProductId, $newProductName, $newAmount),
            ProrationMode::FULL_CREDIT => $this->applyFullCredit($service, $newProductId, $newProductName, $newAmount),
            ProrationMode::PRORATA => $this->applyProrata($service, $newProductId, $newProductName, $newAmount),
            default => throw new RuntimeException("Unknown proration mode [{$mode}]."),
        };

        $this->hooks->fire(HookPoints::UPGRADE_COMPLETED, ['serviceId' => $serviceId, 'mode' => $mode]);

        return $result + ['success' => true];
    }

    /**
     * Remaining days in the *current, already-paid* cycle, and that
     * cycle's total length — the shared inputs both credit-bearing modes
     * measure against.
     *
     * @return array{unusedDays: int, cycleDays: int, oldValuePerDay: float}
     */
    private function remainingCycleMeasure(array $service): array
    {
        $today = new DateTimeImmutable('today');
        $dueDate = new DateTimeImmutable($service['next_due_date']);
        $cycleStart = new DateTimeImmutable(ServiceRepository::previousCycleDate($service['next_due_date'], $service['billing_cycle']));

        $cycleDays = max(1, $cycleStart->diff($dueDate)->days);
        $unusedDays = max(0, min($cycleDays, $today->diff($dueDate)->days));

        return [
            'unusedDays' => $unusedDays,
            'cycleDays' => $cycleDays,
            'oldValuePerDay' => (float) $service['amount'] / $cycleDays,
        ];
    }

    private function applyNone(array $service, int $newProductId, string $newProductName, float $newAmount): array
    {
        $this->services->update((int) $service['id'], [
            'product_id' => $newProductId,
            'product_name' => $newProductName,
            'billing_cycle' => $service['billing_cycle'],
            'amount' => $newAmount,
        ]);

        return ['chargeAmount' => 0.0, 'creditAmount' => 0.0];
    }

    private function applyFullCredit(array $service, int $newProductId, string $newProductName, float $newAmount): array
    {
        $measure = $this->remainingCycleMeasure($service);
        $unusedCredit = round($measure['oldValuePerDay'] * $measure['unusedDays'], 2);
        $today = (new DateTimeImmutable())->format('Y-m-d');

        if ($unusedCredit > 0) {
            $this->credit->add((int) $service['client_id'], $unusedCredit, "Unused time credit — upgrade from \"{$service['product_name']}\"");
        }

        $this->services->update((int) $service['id'], [
            'product_id' => $newProductId,
            'product_name' => $newProductName,
            'billing_cycle' => $service['billing_cycle'],
            'amount' => $newAmount,
        ]);
        $this->services->advanceNextDueDate((int) $service['id'], ServiceRepository::nextCycleDate($today, $service['billing_cycle']));

        $invoiceId = $this->createChargeInvoice($service, $newProductName, $newAmount, $today, 'Upgrade — full new cycle');

        if ($unusedCredit > 0) {
            $this->creditService->applyToInvoice((int) $service['client_id'], $invoiceId);
        }

        return ['chargeAmount' => $newAmount, 'creditAmount' => $unusedCredit, 'invoiceId' => $invoiceId];
    }

    private function applyProrata(array $service, int $newProductId, string $newProductName, float $newAmount): array
    {
        $measure = $this->remainingCycleMeasure($service);
        $unusedCredit = $measure['oldValuePerDay'] * $measure['unusedDays'];
        $newValuePerDay = $newAmount / $measure['cycleDays'];
        $newProratedCost = $newValuePerDay * $measure['unusedDays'];
        $diff = round($newProratedCost - $unusedCredit, 2);
        $today = (new DateTimeImmutable())->format('Y-m-d');

        $this->services->update((int) $service['id'], [
            'product_id' => $newProductId,
            'product_name' => $newProductName,
            'billing_cycle' => $service['billing_cycle'],
            'amount' => $newAmount,
        ]);
        // next_due_date is intentionally left unchanged — prorata keeps the
        // existing renewal schedule, only the price/product changes.

        if ($diff > 0) {
            $invoiceId = $this->createChargeInvoice($service, $newProductName, $diff, $today, 'Upgrade proration (remaining days this cycle)');

            return ['chargeAmount' => $diff, 'creditAmount' => 0.0, 'invoiceId' => $invoiceId];
        }

        if ($diff < 0) {
            $this->credit->add((int) $service['client_id'], -$diff, "Downgrade proration credit — \"{$service['product_name']}\" to \"{$newProductName}\"");

            return ['chargeAmount' => 0.0, 'creditAmount' => -$diff];
        }

        return ['chargeAmount' => 0.0, 'creditAmount' => 0.0];
    }

    private function createChargeInvoice(array $service, string $description, float $amount, string $dueDate, string $note): int
    {
        $client = $this->clients->find((int) $service['client_id']);
        $tax = $this->tax->calculate($client ?? [], $amount);
        $total = $amount + $tax['amount'];
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $currencyLock = $this->currency->lockedColumnsFor($client);

        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, service_id, status, subtotal, tax_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$service['client_id'], $service['id'], 'unpaid', $amount, $tax['amount'], $total, $currencyLock['currency_id'], $currencyLock['currency_rate'], $dueDate, $now, $now]
        );

        $this->db->insert(
            'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
            [$invoiceId, "{$description} — {$note}", $amount]
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
