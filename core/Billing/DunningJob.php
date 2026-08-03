<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientRepository;
use CodeVault\Cron\CronJob;
use CodeVault\Cron\ReportsCronStats;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Database;
use CodeVault\Settings\SettingsRepository;
use RuntimeException;

/**
 * Dunning basics (blueprint §4.4): daily sweep that emails a reminder for
 * every unpaid invoice past its due date and fires InvoiceOverdue so
 * later additions (auto-suspend, escalating reminder stages) can hook in
 * without redesigning the sweep. Real WHMCS supports configurable
 * multi-stage schedules — deferred; this fires once per overdue invoice
 * per day the job runs (daily, per frequencyMinutes()).
 */
final class DunningJob implements CronJob, ReportsCronStats
{
    /** @var array<string, int> counters for the daily activity report */
    private array $stats = [];

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly ClientRepository $clients,
        private readonly EmailDispatcher $mail,
        private readonly HookDispatcher $hooks,
        private readonly CurrencyService $currency,
        private readonly Database $db,
        private readonly SettingsRepository $settings,
        private readonly ServiceLifecycleSettings $policy
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

    /** @return array<string, int> */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * Whole days elapsed since the due date, against the configured late-fee
     * grace. A grace of 0 keeps the original behaviour: the fee lands as soon
     * as the invoice is overdue.
     */
    private function pastLateFeeGrace(?string $dueDate, int $graceDays): bool
    {
        if ($graceDays <= 0) {
            return true;
        }

        if ($dueDate === null || trim($dueDate) === '') {
            return true;
        }

        $due = \DateTimeImmutable::createFromFormat('Y-m-d', substr($dueDate, 0, 10));

        if ($due === false) {
            return true;
        }

        $elapsedDays = (int) $due->setTime(0, 0)->diff(new \DateTimeImmutable('today'))->format('%r%a');

        return $elapsedDays >= $graceDays;
    }

    public function handle(): void
    {
        // Reset per run — the scheduler reads these straight after handle(),
        // and a long-lived process must not accumulate across runs.
        $this->stats = ['late_fees_added' => 0, 'overdue_reminders' => 0];

        $lateFeePercent = (float) $this->settings->get('billing.late_fee_percentage', '5.00');
        $lateFeeGraceDays = $this->policy->lateFeeGraceDays();

        foreach ($this->invoices->overdue() as $invoice) {
            $invoiceId = (int) $invoice['id'];

            // Reminders still go out from the due date; only the fee waits for
            // the grace period, so a client who is a day late hears about it
            // without being charged for it.
            $feeDue = $this->pastLateFeeGrace($invoice['due_date'] ?? null, $lateFeeGraceDays);

            // Check if late fee has already been added
            if ($lateFeePercent > 0 && $feeDue) {
                $hasLateFee = $this->db->selectOne(
                    "SELECT id FROM invoice_items WHERE invoice_id = ? AND description LIKE '%Late Fee%'",
                    [$invoiceId]
                );

                if ($hasLateFee === null) {
                    $subtotal = (float) $invoice['subtotal'];
                    $lateFeeAmount = round($subtotal * ($lateFeePercent / 100), 2);

                    if ($lateFeeAmount > 0) {
                        $newSubtotal = $subtotal + $lateFeeAmount;
                        $newTotal = (float) $invoice['total'] + $lateFeeAmount;

                        $this->db->update(
                            'UPDATE invoices SET subtotal = ?, total = ?, updated_at = NOW() WHERE id = ?',
                            [$newSubtotal, $newTotal, $invoiceId]
                        );

                        $this->db->insert(
                            'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
                            [$invoiceId, "Late Fee ({$lateFeePercent}%)", $lateFeeAmount]
                        );

                        $invoice['subtotal'] = $newSubtotal;
                        $invoice['total'] = $newTotal;
                        $this->stats['late_fees_added']++;
                    }
                }
            }

            $client = $this->clients->find((int) $invoice['client_id']);

            if ($client !== null) {
                try {
                    // formatDocument(), not formatLocked().
                    //
                    // An invoice with currency_id NULL has no currency of its
                    // own — it was imported, or raised before locking, or
                    // locked to the base currency. formatLocked() resolves that
                    // to the SYSTEM DEFAULT, so a Nigerian client's overdue
                    // notice arrived reading "$29,755.95" on an install whose
                    // base currency is USD. formatDocument() falls back to the
                    // client's own currency instead, which is what they were
                    // actually billed in and what every invoice screen shows
                    // them. InvoiceReminderService already did this; dunning
                    // was the outlier.
                    $currencyId = $invoice['currency_id'] !== null ? (int) $invoice['currency_id'] : null;
                    $formattedTotal = $this->currency->formatDocument(
                        (float) $invoice['total'],
                        $currencyId,
                        (float) ($invoice['currency_rate'] ?? 1.0),
                        $this->currency->resolveForClient($client)
                    );

                    $this->mail->sendTemplate('invoice_overdue', $client['email'], [
                        'first_name' => $client['first_name'],
                        'invoice_id' => (string) $invoice['id'],
                        'total' => $formattedTotal,
                        'due_date' => $invoice['due_date'],
                        'company_name' => brand_name(),
                    ], (int) $client['id']);
                    $this->stats['overdue_reminders']++;
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
