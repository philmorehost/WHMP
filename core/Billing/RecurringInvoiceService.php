<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Standalone recurring invoices — the "make this invoice recur" option on
 * /admin/invoices/create (WHMCS-style recurring billing for ad-hoc charges,
 * separate from service/domain renewals which RecurringBillingService owns).
 *
 * A recurring_invoices row is a template; the FIRST invoice is raised
 * immediately when the admin creates it, and every cycle after that the cron
 * RecurringInvoiceJob calls generateDue() to raise the next one. Generation
 * is idempotent: each generated invoice's due_date equals the cycle's
 * next_due_date, and the job skips any (recurring_invoice_id, due_date) pair
 * that already has an invoice — the same guard RecurringBillingService uses.
 */
final class RecurringInvoiceService
{
    public function __construct(
        private readonly RecurringInvoiceRepository $recurring,
        private readonly InvoiceRepository $invoices,
        private readonly Database $db
    ) {
    }

    /**
     * Creates a recurring-invoice template and raises the first invoice
     * immediately. $nextDueDate is when the NEXT invoice will be generated
     * (defaults to one cycle out); it is left untouched by this call.
     *
     * @param array<int, array{description: string, amount: float}> $items
     * @return array{recurring_id: int, invoice_id: int}
     */
    public function createFromAdmin(
        int $clientId,
        array $items,
        string $billingCycle,
        int $dueInDays,
        ?int $currencyId,
        float $currencyRate,
        int $createdByAdminId,
        ?string $nextDueDate = null
    ): array {
        $today = (new DateTimeImmutable())->format('Y-m-d');
        $nextDueDate = $this->validDate($nextDueDate) ?: $this->nextDueDate($today, $billingCycle);
        $amount = round(array_sum(array_column($items, 'amount')), 2);

        $recurringId = $this->recurring->create([
            'client_id' => $clientId,
            'currency_id' => $currencyId,
            'currency_rate' => $currencyRate,
            'billing_cycle' => $billingCycle,
            'items' => $items,
            'amount' => $amount,
            'due_in_days' => $dueInDays,
            'next_due_date' => $nextDueDate,
            'created_by_admin_id' => $createdByAdminId,
            'status' => 'active',
        ]);

        $invoiceId = $this->invoices->createFromItems($clientId, $items, $currencyId, $currencyRate, null, $dueInDays, $recurringId);
        $this->recurring->setLastInvoice($recurringId, $invoiceId);

        return ['recurring_id' => $recurringId, 'invoice_id' => $invoiceId];
    }

    /**
     * Cron sweep: raises an invoice for every active recurring template whose
     * next_due_date has arrived, then rolls that template forward one cycle.
     * Idempotent — a template never gets two invoices for the same due date,
     * even if the job runs twice (or late).
     *
     * @return array<int, int> IDs of invoices generated this run
     */
    public function generateDue(?string $today = null): array
    {
        $today = $this->validDate($today) ?: (new DateTimeImmutable())->format('Y-m-d');
        $generated = [];

        foreach ($this->recurring->activeDue($today) as $ri) {
            $currentDue = (string) $ri['next_due_date'];
            $nextDue = $this->nextDueDate($currentDue, (string) $ri['billing_cycle']);

            // Already generated for this cycle (a prior cron run beat us to
            // it)? Roll the template forward and move on — never double-bill.
            $existing = $this->db->selectOne(
                'SELECT id FROM invoices WHERE recurring_invoice_id = ? AND due_date = ?',
                [$ri['id'], $currentDue]
            );

            if ($existing !== null) {
                $this->recurring->markGenerated((int) $ri['id'], $currentDue, $nextDue, (int) $existing['id']);
                continue;
            }

            $invoiceId = $this->invoices->createFromItems(
                (int) $ri['client_id'],
                $ri['items'],
                $ri['currency_id'] !== null ? (int) $ri['currency_id'] : null,
                (float) $ri['currency_rate'],
                null,
                0,
                (int) $ri['id'],
                $currentDue
            );

            $this->recurring->markGenerated((int) $ri['id'], $currentDue, $nextDue, $invoiceId);
            $generated[] = $invoiceId;
        }

        return $generated;
    }

    /** Adds one billing cycle to a date (YYYY-MM-DD). */
    public function nextDueDate(string $date, string $cycle): string
    {
        $modifier = match ($cycle) {
            'quarterly' => '+3 months',
            'semi_annually' => '+6 months',
            'annually' => '+1 year',
            'biennially' => '+2 years',
            'triennially' => '+3 years',
            default => '+1 month',
        };

        return (new DateTimeImmutable($date))->modify($modifier)->format('Y-m-d');
    }

    /** A clean Y-m-d string, or null when the input isn't one. */
    private function validDate(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $parsed = date_parse($value);

        return ($parsed['error_count'] ?? 1) > 0 ? null : $value;
    }
}
