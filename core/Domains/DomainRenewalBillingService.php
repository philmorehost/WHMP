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
     * On-demand renewal invoice for a single domain — the client-initiated
     * "Renew Now" path, as opposed to the sweep generateDueInvoices() runs
     * from cron. Mirrors RecurringBillingService::generateForService().
     *
     * Deliberately not window-limited, and deliberately not gated on
     * auto_renew: a client who turned auto-renew off is exactly the client
     * who renews by hand. Suspended/expired domains within their grace +
     * redemption window are allowed, since paying is what brings them back.
     *
     * The invoice is raised against the domain's current next_due_date, so
     * paying it extends from the expiry the client already had rather than
     * from today.
     *
     * Idempotent: a still-unpaid renewal invoice for the current due date is
     * returned rather than duplicated.
     *
     * @return array{success: bool, invoiceId?: int, message?: string}
     */
    public function generateForDomain(int $domainId): array
    {
        $domain = $this->domains->find($domainId);

        if ($domain === null) {
            return ['success' => false, 'message' => 'That domain could not be found.'];
        }

        $status = (string) ($domain['status'] ?? '');

        if (in_array($status, ['cancelled', 'terminated'], true)) {
            return ['success' => false, 'message' => 'This domain is ' . $status . ' and can no longer be renewed.'];
        }

        $dueDate = (string) ($domain['next_due_date'] ?? '');

        if ($dueDate === '') {
            return ['success' => false, 'message' => 'This domain has no renewal date set.'];
        }

        // Refuse *before* taking the client's money. DomainService::renew()
        // enforces the same grace + redemption limit at the registrar, but
        // that only runs once this invoice is paid — by which point the
        // client has been charged for a renewal the registrar will reject.
        $closed = $this->renewalWindowClosed($domain);

        if ($closed !== null) {
            return ['success' => false, 'message' => $closed];
        }

        $existing = $this->db->selectOne(
            'SELECT id, status FROM invoices WHERE domain_id = ? AND due_date = ? ORDER BY id DESC LIMIT 1',
            [$domainId, $dueDate]
        );

        if ($existing !== null) {
            if ((string) $existing['status'] === 'unpaid') {
                return ['success' => true, 'invoiceId' => (int) $existing['id']];
            }

            if ((string) $existing['status'] === 'paid') {
                return ['success' => false, 'message' => 'This domain has already been renewed for the current period.'];
            }
        }

        $client = $this->clients->find((int) $domain['client_id']);

        if ($client === null) {
            return ['success' => false, 'message' => 'That domain could not be found.'];
        }

        $tax = $this->tax->calculate($client, (float) $domain['amount']);
        $invoiceId = $this->createRenewalInvoice($domain, $tax, $client);

        $this->hooks->fire(HookPoints::INVOICE_CREATED, ['invoiceId' => $invoiceId, 'domainId' => $domain['id']]);

        return ['success' => true, 'invoiceId' => $invoiceId];
    }

    /**
     * A message when the domain is past its combined grace + redemption
     * window (nothing the registrar will accept), or null when it can still
     * be renewed. Reads grace/redemption straight from domain_pricing, the
     * same source DomainService::renew() uses.
     *
     * @param array<string, mixed> $domain
     */
    private function renewalWindowClosed(array $domain): ?string
    {
        if (empty($domain['expiry_date'])) {
            return null;
        }

        $tld = $this->normalizedTld($domain);

        if ($tld === '') {
            return null;
        }

        $pricing = $this->db->selectOne('SELECT grace_period_days, redemption_period_days FROM domain_pricing WHERE tld = ?', [$tld]);

        if ($pricing === null) {
            return null;
        }

        $now = new DateTimeImmutable();
        $expiry = new DateTimeImmutable((string) $domain['expiry_date']);
        $daysExpired = (int) $now->diff($expiry)->format('%r%a');

        // %r%a is negative once $now is past $expiry.
        if ($daysExpired >= 0) {
            return null;
        }

        $daysPastExpiry = abs($daysExpired);
        $graceDays = (int) ($pricing['grace_period_days'] ?? 30);
        $redemptionDays = (int) ($pricing['redemption_period_days'] ?? 30);
        $maxDays = $graceDays + $redemptionDays;

        if ($daysPastExpiry > $maxDays) {
            return "This domain can no longer be renewed: it is {$daysPastExpiry} days past expiry, beyond the grace period ({$graceDays} days) and redemption period ({$redemptionDays} days).";
        }

        return null;
    }

    /**
     * domain_pricing.tld is stored with a leading dot (".com") while
     * domains.tld is stored without one ("com"); normalise to the dotted form
     * the pricing table uses, falling back to the domain name's suffix.
     *
     * @param array<string, mixed> $domain
     */
    private function normalizedTld(array $domain): string
    {
        $tld = strtolower(trim((string) ($domain['tld'] ?? '')));

        if ($tld === '') {
            $parts = explode('.', strtolower(trim((string) ($domain['domain_name'] ?? ''))));

            if (count($parts) > 1) {
                array_shift($parts);
                $tld = implode('.', $parts);
            }
        }

        if ($tld !== '' && !str_starts_with($tld, '.')) {
            $tld = '.' . $tld;
        }

        return $tld;
    }

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

        // Check if domain exceeds grace period and requires redemption fee.
        // Uses the shared normalizedTld() so this lookup matches the dotted
        // form domain_pricing stores — domains.tld is stored *without* a
        // leading dot, so the raw comparison this replaced never matched and
        // the redemption fee was silently never charged.
        $tld = $this->normalizedTld($domain);

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
