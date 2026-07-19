<?php

declare(strict_types=1);

namespace CodeVault\Cron;

/**
 * A single unit of scheduled work (invoice generation, suspensions, domain
 * sync, reminders, pruning, ...). Every automated job in the platform is
 * one of these, run by the single system cron (blueprint §3).
 */
interface CronJob
{
    public function name(): string;

    /**
     * Minimum minutes between runs (e.g. 60 for hourly, 1440 for daily).
     */
    public function frequencyMinutes(): int;

    public function handle(): void;
}
