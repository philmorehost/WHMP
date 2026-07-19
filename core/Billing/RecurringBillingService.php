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
 * Recurring generation ahead of due date (blueprint §4.4 billing engine —
 * the core of R5). Runs from the cron job, not on a request, since it has
 * to sweep every due service regardless of whether anyone is browsing the
 * site right now.
 */
final class RecurringBillingService
{
    public const DEFAULT_DAYS_AHEAD = 14;

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
     * @return array<int, int> IDs of invoices generated this run
     */
    public function generateDueInvoices(int $daysAhead = self::DEFAULT_DAYS_AHEAD): array
    {
        $generated = [];

        foreach ($this->services->dueForBilling($daysAhead) as $service) {
            // Idempotency guard: if a cron run already generated this
            // cycle's invoice (e.g. the job ran twice before the due date
            // advanced), skip it rather than double-billing the client.
            $existing = $this->db->selectOne(
                'SELECT id FROM invoices WHERE service_id = ? AND due_date = ?',
                [$service['id'], $service['next_due_date']]
            );

            if ($existing !== null) {
                continue;
            }

            $client = $this->clients->find((int) $service['client_id']);

            if ($client === null) {
                continue;
            }

            $taxResult = $this->tax->calculate($client, (float) $service['amount']);
            $invoiceId = $this->createRenewalInvoice($service, $taxResult, $this->currency->lockedColumnsFor($client));

            $this->services->advanceNextDueDate(
                (int) $service['id'],
                ServiceRepository::nextCycleDate($service['next_due_date'], $service['billing_cycle'])
            );

            $this->hooks->fire(HookPoints::INVOICE_CREATED, ['invoiceId' => $invoiceId, 'serviceId' => $service['id']]);
            $generated[] = $invoiceId;
        }

        return $generated;
    }

    /**
     * @param array<string, mixed> $service
     * @param array{rate: float, name: string, amount: float} $tax
     * @param array{currency_id: int|null, currency_rate: float} $currencyLock
     */
    private function createRenewalInvoice(array $service, array $tax, array $currencyLock): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $subtotal = (float) $service['amount'];
        $total = $subtotal + $tax['amount'];
        $cycleLabel = BillingCycle::labels()[$service['billing_cycle']] ?? $service['billing_cycle'];

        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, service_id, status, subtotal, tax_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$service['client_id'], $service['id'], 'unpaid', $subtotal, $tax['amount'], $total, $currencyLock['currency_id'], $currencyLock['currency_rate'], $service['next_due_date'], $now, $now]
        );

        $this->db->insert(
            'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
            [$invoiceId, "{$service['product_name']} ({$cycleLabel}) — Renewal", $subtotal]
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
