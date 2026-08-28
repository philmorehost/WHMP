<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Persistence for standalone recurring invoices (the "make this invoice
 * recur" option on /admin/invoices/create). A recurring_invoices row is a
 * template — client, line items, cycle, currency — that the cron
 * RecurringInvoiceJob turns into a real invoice each cycle. Line items are
 * stored as JSON so an ad-hoc multi-line invoice can be re-raised verbatim.
 */
final class RecurringInvoiceRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * @param array<string, mixed> $fields
     * @return int new recurring-invoice id
     */
    public function create(array $fields): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO recurring_invoices
                (client_id, currency_id, currency_rate, billing_cycle, items, amount, due_in_days,
                 next_due_date, last_invoice_id, status, created_by_admin_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['client_id'],
                $fields['currency_id'] ?? null,
                $fields['currency_rate'] ?? 1.0,
                $fields['billing_cycle'],
                json_encode($fields['items'] ?? []),
                round((float) ($fields['amount'] ?? 0), 2),
                (int) ($fields['due_in_days'] ?? 0),
                $fields['next_due_date'],
                $fields['last_invoice_id'] ?? null,
                $fields['status'] ?? 'active',
                $fields['created_by_admin_id'] ?? null,
                $now,
                $now,
            ]
        );
    }

    /** @return array<string, mixed>|null items decoded from JSON */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne(
            'SELECT ri.*, c.email AS client_email, c.first_name, c.last_name
             FROM recurring_invoices ri
             JOIN clients c ON c.id = ri.client_id
             WHERE ri.id = ?',
            [$id]
        );

        if ($row === null) {
            return null;
        }

        $row['items'] = json_decode((string) ($row['items'] ?? '[]'), true) ?: [];

        return $row;
    }

    /** @return array<int, array<string, mixed>> */
    public function forClient(int $clientId): array
    {
        $rows = $this->db->select(
            'SELECT ri.* FROM recurring_invoices ri WHERE ri.client_id = ? ORDER BY ri.id DESC',
            [$clientId]
        );

        foreach ($rows as &$row) {
            $row['items'] = json_decode((string) ($row['items'] ?? '[]'), true) ?: [];
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> newest first, for the admin management page */
    public function all(): array
    {
        $rows = $this->db->select(
            'SELECT ri.*, c.email AS client_email, c.first_name, c.last_name
             FROM recurring_invoices ri
             JOIN clients c ON c.id = ri.client_id
             ORDER BY ri.status ASC, ri.next_due_date ASC, ri.id DESC'
        );

        foreach ($rows as &$row) {
            $row['items'] = json_decode((string) ($row['items'] ?? '[]'), true) ?: [];
        }

        return $rows;
    }

    /**
     * Active recurring invoices whose next invoice is due on or before $date
     * — the cron sweep's selection.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeDue(string $date): array
    {
        $rows = $this->db->select(
            'SELECT ri.*, c.email AS client_email, c.first_name, c.last_name
             FROM recurring_invoices ri
             JOIN clients c ON c.id = ri.client_id
             WHERE ri.status = \'active\' AND ri.next_due_date <= ?
             ORDER BY ri.next_due_date ASC',
            [$date]
        );

        foreach ($rows as &$row) {
            $row['items'] = json_decode((string) ($row['items'] ?? '[]'), true) ?: [];
        }

        return $rows;
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->update(
            'UPDATE recurring_invoices SET status = ?, updated_at = ? WHERE id = ?',
            [$status, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    /**
     * Records the invoice that was just raised for this recurring template
     * without moving next_due_date — used for the FIRST invoice created at
     * /admin/invoices/create, whose next_due_date already points at the next
     * cycle to generate.
     */
    public function setLastInvoice(int $id, int $invoiceId): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->update(
            'UPDATE recurring_invoices SET last_generated_at = ?, last_invoice_id = ?, updated_at = ? WHERE id = ?',
            [$now, $invoiceId, $now, $id]
        );
    }

    /**
     * Records that an invoice was generated for the current cycle and rolls
     * next_due_date forward to $nextDueDate. The WHERE clause pins the
     * CURRENT next_due_date ($currentDueDate) so a concurrent cron tick that
     * already advanced the row can't advance it twice.
     */
    public function markGenerated(int $id, string $currentDueDate, string $nextDueDate, int $lastInvoiceId): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->update(
            'UPDATE recurring_invoices
             SET next_due_date = ?, last_generated_at = ?, last_invoice_id = ?, updated_at = ?
             WHERE id = ? AND status = \'active\' AND next_due_date = ?',
            [$nextDueDate, $now, $lastInvoiceId, $now, $id, $currentDueDate]
        );
    }
}
