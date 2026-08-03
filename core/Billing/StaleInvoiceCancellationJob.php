<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Cron\CronJob;
use CodeVault\Cron\ReportsCronStats;

/**
 * Cancels unpaid invoices that are long past their due date.
 *
 * Old unpaid invoices — imported history, or renewals for services the client
 * walked away from — are never going to be paid. Left alone they inflate the
 * unpaid and overdue totals on every dashboard and bury the invoices that
 * still need chasing.
 *
 * Does nothing until the admin sets a day count in General Settings, and
 * never touches an invoice belonging to a service that is still active or
 * suspended — see InvoiceRepository::cancelStaleUnpaid() for why that guard
 * exists.
 */
final class StaleInvoiceCancellationJob implements CronJob, ReportsCronStats
{
    /** @var array<string, int> counters for the daily activity report */
    private array $stats = [];

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly ServiceLifecycleSettings $policy
    ) {
    }

    public function name(): string
    {
        return 'stale-invoice-cancellation';
    }

    public function frequencyMinutes(): int
    {
        return 1440;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return $this->stats;
    }

    public function handle(): void
    {
        $this->stats = ['invoices_auto_cancelled' => 0];

        $days = $this->policy->autoCancelUnpaidDays();

        if ($days <= 0) {
            return;
        }

        $this->stats['invoices_auto_cancelled'] = $this->invoices->cancelStaleUnpaid($days);
    }
}
