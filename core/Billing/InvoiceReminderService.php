<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientRepository;
use CodeVault\Mail\EmailDispatcher;
use RuntimeException;
use Throwable;

/**
 * Sends a payment reminder for a single invoice.
 *
 * One path for both the admin's manual "Send Reminder" button and any
 * automated dunning, so a client can never receive two differently-worded
 * reminders for the same invoice depending on which one fired.
 *
 * The amount is formatted through CurrencyService::formatDocument(), the same
 * rule the client's own invoice list uses — a reminder quoting a different
 * figure or symbol from the invoice it refers to is worse than no reminder.
 */
final class InvoiceReminderService
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly ClientRepository $clients,
        private readonly EmailDispatcher $mail,
        private readonly CurrencyService $currency
    ) {
    }

    /**
     * @return array{sent: bool, reason?: string}
     */
    public function send(int $invoiceId): array
    {
        $invoice = $this->invoices->find($invoiceId);

        if ($invoice === null) {
            return ['sent' => false, 'reason' => 'not-found'];
        }

        // Only chase money that is actually owed. Reminding someone about an
        // invoice they already paid is the kind of mistake that generates a
        // support ticket and erodes trust in every later reminder.
        if (($invoice['status'] ?? '') !== 'unpaid') {
            return ['sent' => false, 'reason' => 'not-unpaid'];
        }

        $client = $this->clients->find((int) $invoice['client_id']);

        if ($client === null || trim((string) ($client['email'] ?? '')) === '') {
            return ['sent' => false, 'reason' => 'no-client-email'];
        }

        $currencyId = $invoice['currency_id'] !== null ? (int) $invoice['currency_id'] : null;
        $formattedTotal = $this->currency->formatDocument(
            (float) $invoice['total'],
            $currencyId,
            (float) ($invoice['currency_rate'] ?? 1.0),
            $this->currency->resolveForClient($client)
        );

        try {
            $this->mail->sendTemplate('invoice_overdue', (string) $client['email'], [
                'first_name' => (string) ($client['first_name'] ?? ''),
                'invoice_id' => (string) $invoice['id'],
                'total' => $formattedTotal,
                'due_date' => (string) ($invoice['due_date'] ?? ''),
                'company_name' => brand_name(),
            ], (int) $client['id']);
        } catch (RuntimeException $e) {
            // Template missing or renamed — report it rather than counting a
            // reminder that never left.
            return ['sent' => false, 'reason' => 'template-missing'];
        } catch (Throwable) {
            return ['sent' => false, 'reason' => 'send-failed'];
        }

        return ['sent' => true];
    }

    /**
     * @param array<int, int> $invoiceIds
     * @return array{sent: int, skipped: int}
     */
    public function sendMany(array $invoiceIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $invoiceIds), static fn (int $id): bool => $id > 0)));

        $sent = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            if ($this->send($id)['sent']) {
                $sent++;
                continue;
            }

            $skipped++;
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }
}
