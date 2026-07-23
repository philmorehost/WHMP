<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientRepository;
use CodeVault\Cron\CronJob;
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
final class DunningJob implements CronJob
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly ClientRepository $clients,
        private readonly EmailDispatcher $mail,
        private readonly HookDispatcher $hooks,
        private readonly CurrencyService $currency,
        private readonly Database $db,
        private readonly SettingsRepository $settings
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
        $lateFeePercent = (float) $this->settings->get('billing.late_fee_percentage', '5.00');

        foreach ($this->invoices->overdue() as $invoice) {
            $invoiceId = (int) $invoice['id'];

            // Check if late fee has already been added
            if ($lateFeePercent > 0) {
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
                    }
                }
            }

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
