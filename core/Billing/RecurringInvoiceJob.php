<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Cron\CronJob;
use CodeVault\Cron\ReportsCronStats;

/**
 * Generates the next invoice for every active standalone recurring invoice
 * whose next_due_date has arrived (the "make this invoice recur" option on
 * /admin/invoices/create). Runs from the single system cron, once a day,
 * like the service/domain renewal sweep — RecurringInvoiceService::generateDue()
 * is idempotent, so a late or repeated run never double-bills a client.
 */
final class RecurringInvoiceJob implements CronJob, ReportsCronStats
{
    /** @var array<string, int> counters for the daily activity report */
    private array $stats = [];

    public function __construct(
        private readonly RecurringInvoiceService $service
    ) {
    }

    public function name(): string
    {
        return 'recurring-invoices';
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
        $this->stats = ['invoices_generated' => count($this->service->generateDue())];
    }
}
