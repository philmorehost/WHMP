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
            $invoiceId = $this->createRenewalInvoice($service, $taxResult, $this->currency->denominateFor($client));

            // Deliberately NOT advancing next_due_date here. The renewal date
            // only rolls forward once the renewal invoice is actually PAID
            // (ServiceRenewalService via the InvoicePaid listener) — a client
            // who has not paid must not have their next renewal silently
            // pushed out a cycle. The idempotency guard above keys on
            // (service_id, next_due_date), so the same unpaid invoice is
            // never re-generated on later cron ticks.
            $this->hooks->fire(HookPoints::INVOICE_CREATED, ['invoiceId' => $invoiceId, 'serviceId' => $service['id']]);
            $generated[] = $invoiceId;
        }

        return $generated;
    }

    /**
     * On-demand renewal invoice for a single service — the client-initiated
     * "Renew Now" path, as opposed to the sweep generateDueInvoices() runs
     * from cron.
     *
     * Deliberately not window-limited: a client may renew at any time before
     * the due date, not just inside the N-day reminder window. The invoice is
     * raised against the service's *current* next_due_date, so paying it
     * rolls the cycle forward by exactly one billing period from the date the
     * client already had rather than from today (ServiceRenewalService
     * advances from the existing date — see its note on late payments).
     *
     * Idempotent, keyed the same way the cron guard is: a still-unpaid
     * renewal invoice for the current due date is returned rather than
     * duplicated, so a client clicking Renew twice lands on the invoice they
     * already have instead of being billed twice for one cycle.
     *
     * Suspended services are allowed here (the cron sweep skips them): a
     * client suspended for non-payment has to be able to renew to pay, and
     * paying is what lifts the suspension.
     *
     * @return array{success: bool, invoiceId?: int, message?: string}
     */
    public function generateForService(int $serviceId): array
    {
        $service = $this->services->findById($serviceId);

        if ($service === null) {
            return ['success' => false, 'message' => 'That service could not be found.'];
        }

        $status = (string) ($service['status'] ?? '');

        if (in_array($status, ['cancelled', 'terminated'], true)) {
            return ['success' => false, 'message' => 'This service is ' . $status . ' and can no longer be renewed.'];
        }

        $cycle = (string) ($service['billing_cycle'] ?? '');
        $dueDate = (string) ($service['next_due_date'] ?? '');

        if ($dueDate === '' || $cycle === '' || $cycle === 'one_time') {
            return ['success' => false, 'message' => 'This service does not renew on a recurring billing cycle.'];
        }

        $existing = $this->db->selectOne(
            'SELECT id, status FROM invoices WHERE service_id = ? AND due_date = ? ORDER BY id DESC LIMIT 1',
            [$serviceId, $dueDate]
        );

        if ($existing !== null) {
            if ((string) $existing['status'] === 'unpaid') {
                return ['success' => true, 'invoiceId' => (int) $existing['id']];
            }

            // A paid invoice for the *current* due date means the cycle is
            // already settled but the date did not roll forward (e.g. the
            // renewal step failed after payment). Never bill it again.
            if ((string) $existing['status'] === 'paid') {
                return ['success' => false, 'message' => 'This service has already been renewed for the current period.'];
            }

            // Cancelled/refunded: a dead invoice must not be resurrected — fall
            // through and raise a fresh one for this cycle.
        }

        $client = $this->clients->find((int) $service['client_id']);

        if ($client === null) {
            return ['success' => false, 'message' => 'That service could not be found.'];
        }

        $taxResult = $this->tax->calculate($client, (float) $service['amount']);
        $invoiceId = $this->createRenewalInvoice($service, $taxResult, $this->currency->denominateFor($client));

        $this->hooks->fire(HookPoints::INVOICE_CREATED, ['invoiceId' => $invoiceId, 'serviceId' => $serviceId]);

        return ['success' => true, 'invoiceId' => $invoiceId];
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

        $identifier = ServiceRepository::invoiceIdentifierSuffix($service['domain'] ?? null, $service['hostname'] ?? null);

        $this->db->insert(
            'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
            [$invoiceId, "{$service['product_name']} ({$cycleLabel}) — Renewal{$identifier}", $subtotal]
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
