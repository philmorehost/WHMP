<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientRepository;
use CodeVault\Cron\CronJob;

/**
 * Daily auto-charge sweep (R5). Runs before dunning: for every unpaid
 * invoice that has reached its due date, if the client has a saved payment
 * method it charges it via AutoChargeService. Successful charges settle the
 * invoice so the dunning job never emails an overdue notice for them;
 * failures are simply left for dunning to chase, exactly as before this
 * feature existed. Idempotent — an already-paid invoice is skipped, and each
 * attempt uses a fresh gateway reference.
 */
final class AutoChargeJob implements CronJob
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly ClientRepository $clients,
        private readonly AutoChargeService $autoCharge
    ) {
    }

    public function name(): string
    {
        return 'auto-charge';
    }

    public function frequencyMinutes(): int
    {
        return 1440;
    }

    public function handle(): void
    {
        foreach ($this->invoices->dueUnpaid() as $invoice) {
            $client = $this->clients->find((int) $invoice['client_id']);

            if ($client === null) {
                continue;
            }

            try {
                $this->autoCharge->attempt($invoice, $client);
            } catch (\Throwable) {
                // A single gateway/network failure must not stop the sweep —
                // leave this invoice for dunning and move on.
                continue;
            }
        }
    }
}
