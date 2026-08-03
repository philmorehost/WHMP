<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Billing\CurrencyService;
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
        private readonly HookDispatcher $hooks,
        private readonly CurrencyService $currency
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
            $invoiceId = $this->createRenewalInvoice($domain, $tax, $client);

            $this->hooks->fire(HookPoints::INVOICE_CREATED, ['invoiceId' => $invoiceId, 'domainId' => $domain['id']]);
            $generated[] = $invoiceId;
        }

        return $generated;
    }

    /**
     * @param array<string, mixed> $domain
     * @param array{rate: float, name: string, amount: float} $tax
     */
    /**
     * @param array<string, mixed> $domain
     * @param array{rate: float, name: string, amount: float} $tax
     */
    private function createRenewalInvoice(array $domain, array $tax, ?array $client = null): int
    {
        $now = new DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');
        $subtotal = (float) $domain['amount'];
        $redemptionFee = 0.0;

        // Check if domain exceeds grace period and requires redemption fee
        $tld = strtolower(trim((string) ($domain['tld'] ?? '')));
        if ($tld === '') {
            $parts = explode('.', strtolower(trim((string) $domain['domain_name'])));
            if (count($parts) > 1) {
                array_shift($parts);
                $tld = '.' . implode('.', $parts);
            }
        }

        if ($tld !== '') {
            $tldPricing = $this->db->selectOne('SELECT * FROM domain_pricing WHERE tld = ?', [$tld]);
            if ($tldPricing !== null && !empty($domain['expiry_date'])) {
                $expiryDate = new DateTimeImmutable((string) $domain['expiry_date']);
                $daysExpired = (int) $now->diff($expiryDate)->format('%r%a');
                // %r%a: negative if $now > $expiryDate
                if ($daysExpired < 0) {
                    $daysPastExpiry = abs($daysExpired);
                    $graceDays = (int) ($tldPricing['grace_period_days'] ?? 30);
                    $redemptionDays = (int) ($tldPricing['redemption_period_days'] ?? 30);

                    if ($daysPastExpiry > $graceDays && $daysPastExpiry <= ($graceDays + $redemptionDays)) {
                        // Unlike $domain['amount'] below (already converted
                        // once, at registration), redemption_fee is a fresh,
                        // never-before-charged raw catalog read — the same
                        // "convert once, at first charge" case as
                        // CheckoutService/ProrationService.
                        $redemptionFee = $this->currency->convert(
                            (float) ($tldPricing['redemption_fee'] ?? 0.0),
                            $this->currency->rateFor($this->currency->resolveForClient($client))
                        );
                    }
                }
            }
        }

        $subtotalWithRedemption = $subtotal + $redemptionFee;
        $total = $subtotalWithRedemption + $tax['amount'];

        // Lock the client's currency onto the invoice, exactly as every other
        // invoice-generating path does. Without this the row fell back to
        // currency_id = NULL and the column default rate of 1.0, so a domain
        // renewal for a client billed in (say) NGN rendered with the system
        // default symbol against an unconverted base amount — the wrong
        // currency AND the wrong number.
        $currencyLock = $this->currency->denominateFor($client);

        $invoiceId = (int) $this->db->insert(
            'INSERT INTO invoices (client_id, domain_id, status, subtotal, tax_amount, total, currency_id, currency_rate, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $domain['client_id'],
                $domain['id'],
                'unpaid',
                $subtotalWithRedemption,
                $tax['amount'],
                $total,
                $currencyLock['currency_id'],
                $currencyLock['currency_rate'],
                $domain['next_due_date'],
                $nowStr,
                $nowStr,
            ]
        );

        $this->db->insert(
            'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
            [$invoiceId, "{$domain['domain_name']} — Domain Renewal", $subtotal]
        );

        if ($redemptionFee > 0) {
            $this->db->insert(
                'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
                [$invoiceId, "{$domain['domain_name']} — Domain Redemption Fee", $redemptionFee]
            );
        }

        if ($tax['amount'] > 0) {
            $this->db->insert(
                'INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)',
                [$invoiceId, "{$tax['name']} ({$tax['rate']}%)", $tax['amount']]
            );
        }

        return $invoiceId;
    }
}
