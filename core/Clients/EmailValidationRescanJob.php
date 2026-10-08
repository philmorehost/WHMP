<?php

declare(strict_types=1);

namespace CodeVault\Clients;

use CodeVault\Cron\CronJob;
use CodeVault\Mail\EmailSuppression;
use DateTimeImmutable;
use Throwable;

/**
 * Keeps the Invalid Email Blocker's list current without anyone clicking
 * "Scan": re-runs the Email Validation scan every `rescan_days` days (addon
 * setting, default 7) while the addon is active.
 *
 * That matters both ways — a client who fixes their address (or whose domain
 * comes back) is un-blocked by the next scan, and newly dead addresses are
 * caught before the next invoice run. Checked hourly; scans only when due. A
 * scan that aborts because DNS is down changes nothing, so it is simply retried
 * on the next tick.
 */
final class EmailValidationRescanJob implements CronJob
{
    public function __construct(
        private readonly EmailSuppression $suppression,
        private readonly ClientEmailValidationRepository $results,
        private readonly ClientEmailValidationService $scanner
    ) {
    }

    public function name(): string
    {
        return 'email-validation-rescan';
    }

    public function frequencyMinutes(): int
    {
        return 60;
    }

    public function handle(): void
    {
        if (!$this->isDue()) {
            return;
        }

        $this->scanner->scanAll();
    }

    public function isDue(?DateTimeImmutable $now = null): bool
    {
        try {
            if (!$this->suppression->isActive()) {
                return false;
            }

            $days = (int) $this->suppression->settings()['rescan_days'];

            if ($days <= 0) {
                return false;
            }

            $last = $this->results->summary()['lastScanAt'];

            if ($last === null) {
                return true;
            }

            $now ??= new DateTimeImmutable();

            return (new DateTimeImmutable((string) $last))->modify('+' . $days . ' days') <= $now;
        } catch (Throwable) {
            return false;
        }
    }
}
