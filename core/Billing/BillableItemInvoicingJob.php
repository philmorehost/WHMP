<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientRepository;
use CodeVault\Cron\CronJob;
use CodeVault\Database;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use DateTimeImmutable;

/**
 * Turns ad-hoc billable items (blueprint §4.3 — e.g. a resolved support
 * ticket converted to a charge) into real invoices, the same daily-sweep
 * shape as RecurringBillingService/DunningJob. One invoice per billable
 * item — these are one-off charges, not worth the batching complexity of
 * a multi-item statement.
 */
final class BillableItemInvoicingJob implements CronJob
{
    public function __construct(
        private readonly BillableItemRepository $billableItems,
        private readonly ClientRepository $clients,
        private readonly TaxCalculator $tax,
        private readonly CurrencyService $currency,
        private readonly Database $db,
        private readonly HookDispatcher $hooks
    ) {
    }

    public function name(): string
    {
        return 'billable-item-invoicing';
    }

    public function frequencyMinutes(): int
    {
        return 1440;
    }

    public function handle(): void
    {
        foreach ($this->billableItems->uninvoiced() as $item) {
            $client = $this->clients->find((int) $item['client_id']);

            if ($client === null) {
                continue;
            }

            $taxResult = $this->tax->calculate($client, (float) $item['amount']);
            $invoiceId = $this->createInvoice($item, $taxResult, $this->currency->lockedColumnsFor($client));

            $this->billableItems->markInvoiced((int) $item['id'], $invoiceId);
            $this->hooks->fire(HookPoints::INVOICE_CREATED, ['invoiceId' => $invoiceId, 'billableItemId' => $item['id']]);
        }
    }

    /**
     * @param array<string, mixed> $item
     * @param array{rate: float, name: string, amount: float} $tax
     * @param array{currency_id: int|null, currency_rate: float} $currencyLock
     */
    private function createInvoice(array $item, array $tax, array $currencyLock): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $subtotal = (float) $item['amount'];
        $total = $subtotal + $tax['amount'];
        $dueDate = (new DateTimeImmutable())->format('Y-m-d');

        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, status, subtotal, tax_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$item['client_id'], 'unpaid', $subtotal, $tax['amount'], $total, $currencyLock['currency_id'], $currencyLock['currency_rate'], $dueDate, $now, $now]
        );

        $this->db->insert(
            'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
            [$invoiceId, $item['description'], $subtotal]
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
