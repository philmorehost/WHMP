<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\TaxCalculator;
use CodeVault\Cron\CronJob;
use CodeVault\Database;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Settings\SettingsRepository;
use DateTimeImmutable;

/**
 * Invoices each store for the cost of the orders it took, grouping accruals by
 * closed billing period (calendar month by default, or ISO week). A below-minimum
 * period can carry into the next invoice. Uses the ordinary invoice path — the
 * same daily sweep as BillableItemInvoicingJob.
 *
 * The reseller pays us at retail through their storefront (Phase 3), so this is
 * the other half of that: we bill them the cost. The invoice lands on their
 * client account, so it appears in their client area and can be paid with the
 * same gateways as everything else.
 *
 * **Idempotency is a property of the data, not of this job.** An order's cost
 * is billed when orders.reseller_cost_invoice_id is stamped, and the guard on
 * that stamp lives inside the UPDATE. So running twice bills once; running
 * after a missed period bills the period that was missed; and an order whose
 * invoice is later deleted is simply unbilled again rather than lost.
 *
 * Nothing here suspends a store for non-payment. Taking a reseller's storefront
 * offline also takes their customers offline, so that stays an explicit admin
 * action — arrears are surfaced by the report instead.
 */
final class ResellerCostBillingJob implements CronJob
{
    public function __construct(
        private readonly ResellerCostService $costs,
        private readonly ResellerCostRepository $repository,
        private readonly CurrencyService $currency,
        private readonly TaxCalculator $tax,
        private readonly SettingsRepository $settings,
        private readonly Database $db,
        private readonly HookDispatcher $hooks,
        private readonly ResellerLedgerService $ledger,
        private readonly InvoiceRepository $invoices
    ) {
    }

    public function name(): string
    {
        return 'reseller-cost-billing';
    }

    public function frequencyMinutes(): int
    {
        return 1440;
    }

    public function handle(): void
    {
        $this->run(false);
    }

    /** Explicit admin run; it remains available when scheduled billing is off. */
    public function billNow(): void
    {
        $this->run(true);
    }

    private function run(bool $manual): void
    {
        if (!$manual && $this->settings->get('reseller.billing_auto', '1') !== '1') {
            return;
        }

        $today = (new DateTimeImmutable())->format('Y-m-d');
        $dueDays = max(0, (int) $this->settings->get('reseller.billing_due_days', '7'));
        $minimum = max(0.0, (float) $this->settings->get('reseller.billing_minimum', '0.00'));
        $cadence = $this->settings->get('reseller.billing_period', ResellerCostService::CADENCE_MONTHLY);
        $cadence = $cadence === ResellerCostService::CADENCE_WEEKLY
            ? ResellerCostService::CADENCE_WEEKLY
            : ResellerCostService::CADENCE_MONTHLY;

        foreach ($this->costs->duePeriods($today, $minimum, $cadence) as $period) {
            $invoiceId = $this->raise($period, $dueDays);

            // The debit side of the reseller's account: the cost invoice is the
            // document, and this records it against the same balance the retail
            // receipts feed. Deliberately AFTER raise() returns, so it can only
            // ever happen for an invoice that actually committed -- raise() rolls
            // the invoice and the orders it claims back together, or not at all.
            $posted = $this->ledger->recordCostInvoice($invoiceId);

            // Settle the invoice itself, and only when the debit actually posted.
            //
            // Decision 3: cost invoices are settled from the balance, so no cash is
            // ever due on this document. Without this the invoice stays 'unpaid',
            // and since InvoiceRepository::overdue() is nothing more than "unpaid
            // and past due", the dunning sweep then emails the reseller reminders
            // for a bill already taken off their balance -- and adds a 5% late fee
            // that is NEVER debited, because the debit happened above and is guarded
            // by hasCostEntryForInvoice(). The two records would then disagree by
            // exactly the fee. Found 2026-09-29.
            //
            // Through the repository rather than the payment path on purpose:
            // paying an invoice normally fires INVOICE_PAID, which runs the
            // provisioning and notification listeners for a document that is not a
            // customer sale. markPaid() is a plain guarded UPDATE that also stamps
            // paid_at, so the reporting queries keyed on "paid AND paid_at >= ?"
            // still see this invoice.
            //
            // The null check is DEFENSIVE and not reachable through this job today:
            // raise() only ever creates invoices from duePeriods(), which requires a
            // store, so recordCostInvoice() always posts for a freshly raised
            // invoice and always returns the entry id. It stays because the
            // alternative -- settling on "an invoice was raised" rather than "the
            // debit posted" -- would write off money nobody was ever charged for if
            // that ever changed. There is deliberately no test for the null branch,
            // because a state the code cannot produce cannot be tested honestly.
            if ($posted !== null) {
                $this->invoices->markPaid($invoiceId);
            }

            $this->hooks->fire(HookPoints::INVOICE_CREATED, [
                'invoiceId' => $invoiceId,
                'resellerId' => $period['reseller_id'],
                'resellerCostPeriod' => $period['period'],
            ]);
        }
    }

    /**
     * Create the cost invoice and claim its orders in ONE transaction.
     *
     * The invoice and the marker on the orders must agree. An invoice whose
     * orders were claimed by someone else would charge the reseller for money
     * we did not record; orders claimed by an invoice that failed would never be
     * billed at all. So either both happen or neither does, and a mismatch is
     * raised rather than papered over — the next run re-reads and retries.
     *
     * @param array<string, mixed> $period
     */
    private function raise(array $period, int $dueDays): int
    {
        $client = $period['client'];
        $orders = $period['orders'];

        $subtotal = (float) $period['amount'];
        $tax = $this->tax->calculate($client, $subtotal);
        $total = round($subtotal + (float) $tax['amount'], 2);

        // The reseller's own currency, denominated: the stored figure IS the
        // amount they owe, and currency_rate = 1.0 records that.
        $lock = $this->currency->denominateFor($client);

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $dueDate = (new DateTimeImmutable())->modify("+{$dueDays} days")->format('Y-m-d');

        return $this->db->transaction(function () use ($client, $orders, $subtotal, $tax, $total, $lock, $now, $dueDate): int {
            $invoiceId = (int) $this->db->insert(
                'INSERT INTO invoices (client_id, status, subtotal, tax_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $client['id'],
                    'unpaid',
                    $subtotal,
                    $tax['amount'],
                    $total,
                    $lock['currency_id'],
                    $lock['currency_rate'],
                    $dueDate,
                    $now,
                    $now,
                ]
            );

            foreach ($orders as $order) {
                // order_id is recorded so the cost can be REVERSED per order if the
                // sale is later refunded. Without it the order is only identifiable
                // from the description text, and there is nothing to reverse against.
                // The tax line below leaves it NULL: it belongs to no order.
                $this->db->insert(
                    'INSERT INTO invoice_items (invoice_id, description, amount, order_id) VALUES (?, ?, ?, ?)',
                    [$invoiceId, $this->describe($order), $order['converted_cost'], (int) $order['id']]
                );
            }

            if ((float) $tax['amount'] > 0) {
                $this->db->insert(
                    'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
                    [$invoiceId, "{$tax['name']} ({$tax['rate']}%)", $tax['amount']]
                );
            }

            $claimed = $this->repository->markBilled(
                array_map(static fn (array $order): int => (int) $order['id'], $orders),
                $invoiceId
            );

            if ($claimed !== count($orders)) {
                throw new \RuntimeException(sprintf(
                    'Reseller cost invoice rolled back: claimed %d of %d orders for store %d.',
                    $claimed,
                    count($orders),
                    (int) $orders[0]['reseller_id']
                ));
            }

            return $invoiceId;
        });
    }

    /**
     * One line per order, identified by OUR order number and date only.
     *
     * Deliberately not the customer's name or email: a store's customer may be
     * one of our own clients (a store attributes only accounts it created, and
     * an existing client who buys there keeps their owner), and a reseller must
     * not be able to read our client list off their invoice.
     *
     * @param array<string, mixed> $order
     */
    private function describe(array $order): string
    {
        $placed = substr((string) $order['created_at'], 0, 10);

        return sprintf('Store order #%d — %s', (int) $order['id'], $placed);
    }
}
