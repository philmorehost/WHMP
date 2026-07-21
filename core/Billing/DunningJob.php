<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientRepository;
use CodeVault\Cron\CronJob;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Mail\EmailDispatcher;
use RuntimeException;

/**
 * Dunning basics (blueprint §4.4): daily sweep that emails a reminder for
 * every unpaid invoice past its due date and fires InvoiceOverdue so
 * later additions (auto-suspend, escalating reminder stages) can hook in
 * without redesigning the sweep. Real WHMCS supports configurable
 * multi-stage schedules — deferred; this fires once per overdue invoice
 * per day the job runs (daily, per frequencyMinutes()).
 */
final class DunningJob implements CronJob
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly ClientRepository $clients,
        private readonly EmailDispatcher $mail,
        private readonly HookDispatcher $hooks,
        private readonly CurrencyService $currency
    ) {
    }

    public function name(): string
    {
        return 'dunning';
    }

    public function frequencyMinutes(): int
    {
        return 1440;
    }

    public function handle(): void
    {
        foreach ($this->invoices->overdue() as $invoice) {
            $client = $this->clients->find((int) $invoice['client_id']);

            if ($client !== null) {
                try {
                    $currencyId = $invoice['currency_id'] !== null ? (int) $invoice['currency_id'] : null;
                    $formattedTotal = $this->currency->formatLocked((float) $invoice['total'], $currencyId, (float) $invoice['currency_rate']);

                    $this->mail->sendTemplate('invoice_overdue', $client['email'], [
                        'first_name' => $client['first_name'],
                        'invoice_id' => (string) $invoice['id'],
                        'total' => $formattedTotal,
                        'due_date' => $invoice['due_date'],
                        'company_name' => 'CodeVault',
                    ], (int) $client['id']);
                } catch (RuntimeException) {
                    // Template not seeded/renamed — skip rather than crash the whole sweep.
                }
            }

            $this->hooks->fire(HookPoints::INVOICE_OVERDUE, [
                'invoiceId' => $invoice['id'],
                'clientId' => $invoice['client_id'],
                'dueDate' => $invoice['due_date'],
            ]);
        }
    }
}
