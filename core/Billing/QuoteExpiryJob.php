<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Cron\CronJob;

/**
 * Auto-expires a draft/sent quote once its valid_until date has passed —
 * matches real WHMCS quote-expiry behavior (blueprint §4.1 "My Quotes").
 * An accepted/declined quote is a closed decision and is never touched
 * here regardless of its valid_until.
 */
final class QuoteExpiryJob implements CronJob
{
    public function __construct(
        private readonly QuoteRepository $quotes
    ) {
    }

    public function name(): string
    {
        return 'quote-expiry';
    }

    public function frequencyMinutes(): int
    {
        return 1440;
    }

    public function handle(): void
    {
        foreach ($this->quotes->overdue() as $quote) {
            $this->quotes->updateStatus((int) $quote['id'], 'expired');
        }
    }
}
