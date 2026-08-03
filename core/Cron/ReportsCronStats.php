<?php

declare(strict_types=1);

namespace CodeVault\Cron;

/**
 * Opt-in counters for the daily activity report.
 *
 * CronJob::handle() returns void, and deliberately stays that way — changing
 * it would touch every job in the platform. A job that has something worth
 * reporting implements this as well, and the scheduler asks for the counters
 * after handle() completes. Jobs that don't implement it simply contribute
 * nothing, and their tiles read 0 in the report.
 *
 * Keys are free-form but should stay stable, since the report sums them by
 * name across a 24h window (e.g. 'invoices_generated' => 3).
 */
interface ReportsCronStats
{
    /**
     * Counters describing what the *most recent* handle() call did.
     * Called once, immediately after handle(); implementations should reset
     * their tally at the start of each handle() so runs don't accumulate.
     *
     * @return array<string, int>
     */
    public function stats(): array;
}
