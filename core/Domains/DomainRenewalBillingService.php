<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Billing\TaxCalculator;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use DateTimeImmutable;

/**
 * Recurring generation ahead of due date, for domains (mirrors
 * RecurringBillingService from R5 — same idempotency guard, same shape,
 * different entity). The actual registrar renew() call happens once this
 * invoice is paid (blueprint: renewal shouldn't cost money against the
 * registrar before the client has paid for it) — wired via a
 * DomainRenewedOnPayment hook listener, not here.
 */
final class DomainRenewalBillingService
{
    public const DEFAULT_DAYS_AHEAD = 30;

    public function __construct(
        private readonly DomainRepository $domains,
        private readonly ClientRepository $clients,
        private readonly TaxCalculator $tax,
        private readonly Database $db,
        private readonly HookDispatcher $hooks
    ) {
    }

    /** @return array<int, int> IDs of invoices generated this run */
    public function generateDueInvoices(int $daysAhead = self::DEFAULT_DAYS_AHEAD): array
    {
        $generated = [];

        foreach ($this->domains->dueForRenewal($daysAhead) as $domain) {
            $existing = $this->db->selectOne(
                'SELECT id FROM invoices WHERE domain_id = ? AND due_date = ?',
                [$domain['id'], $domain['next_due_date']]
            );

            if ($existing !== null) {
                continue;
            }

            $client = $this->clients->find((int) $domain['client_id']);

            if ($client === null) {
                continue;
            }

            $tax = $this->tax->calculate($client, (float) $domain['amount']);
            $invoiceId = $this->createRenewalInvoice($domain, $tax);

            $this->hooks->fire(HookPoints::INVOICE_CREATED, ['invoiceId' => $invoiceId, 'domainId' => $domain['id']]);
            $generated[] = $invoiceId;
        }

        return $generated;
    }

    /**
     * @param array<string, mixed> $domain
     * @param array{rate: float, name: string, amount: float} $tax
     */
    private function createRenewalInvoice(array $domain, array $tax): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $subtotal = (float) $domain['amount'];
        $total = $subtotal + $tax['amount'];

        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, domain_id, status, subtotal, tax_amount, total, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$domain['client_id'], $domain['id'], 'unpaid', $subtotal, $tax['amount'], $total, $domain['next_due_date'], $now, $now]
        );

        $this->db->insert(
            'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
            [$invoiceId, "{$domain['domain_name']} — Domain Renewal", $subtotal]
        );

        if ($tax['amount'] > 0) {
            $this->db->insert(
                'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
                [$invoiceId, "{$tax['name']} ({$tax['rate']}%)", $tax['amount']]
            );
        }

        return $invoiceId;
    }
}
