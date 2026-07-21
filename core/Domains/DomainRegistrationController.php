<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Cart\Cart;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Database;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;

/**
 * Standalone "Register a New Domain" search page — separate from the
 * product-page domain field (require_domain products), which is for
 * buying a domain alongside hosting. This is for a domain on its own.
 *
 * The cart has no concept of a domain-only line (Cart::add()/
 * CartService::priced() require a real product_id — see
 * migration 0103's docblock), so a domain added here rides on a hidden
 * $0 "Domain Registration" carrier product; CheckoutService already adds
 * the domain's own price on top of whatever product carries a line's
 * domain_options, independently of the product itself.
 */
final class DomainRegistrationController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly DomainPricingRepository $domainPricing,
        private readonly DomainService $domainService,
        private readonly DomainSettings $domainSettings,
        private readonly Cart $cart,
        private readonly Database $db,
        private readonly \CodeVault\Ai\AiProvider $ai,
        private readonly \CodeVault\Ai\AiSettings $aiSettings
    ) {
    }

    public function searchForm(Request $request): Response
    {
        // Public — prospective clients can search/browse TLDs without an
        // account (the session cart works for guests; login is only required
        // at checkout, WHMCS-style).
        $query = trim((string) $request->query('domain', (string) $request->query('domain_search', '')));

        return $this->page('domains.register', [
            'query' => $query,
            'results' => $query !== '' ? $this->search($query) : [],
            'error' => $request->query('error'),
            'defaultNameservers' => $this->domainSettings->defaultNameservers(),
            'categories' => $this->domainPricing->allByCategory(),
            'featured' => array_values(array_filter([
                $this->domainPricing->findByTld('.com'),
                $this->domainPricing->findByTld('.net'),
            ])),
        ]);
    }

    /**
     * Domain Spinner — given a base name, generates common
     * prefix/suffix/hyphenation variations and checks each one's
     * availability across whichever TLDs the admin opted into the
     * spinner (DomainPricingRepository::spinnerEnabled(), a deliberately
     * separate/usually-smaller list than every TLD offered for direct
     * registration, so one spin doesn't fan out into dozens of live
     * registrar API calls). Capped at MAX_CHECKS total availability
     * checks for the same reason — a spin is a suggestion tool, not an
     * exhaustive search.
     */
    private const SPIN_MAX_CHECKS = 15;

    public function spin(Request $request): Response
    {
        $name = $this->normalize((string) $request->query('name', ''));
        $name = preg_replace('/[^a-z0-9-]/', '', $name) ?? '';

        if ($name === '') {
            return Response::json(['suggestions' => []]);
        }

        $tlds = $this->domainPricing->spinnerEnabled();

        if ($tlds === []) {
            return Response::json(['suggestions' => [], 'message' => 'No TLDs are enabled for the Domain Spinner yet — an admin can turn this on in Domain Pricing.']);
        }

        // Rule-based variations as a reliable, zero-cost fallback. 
        $candidates = array_values(array_unique($this->variationsOf($name)));
        $suggestions = [];
        $checks = 0;

        foreach ($candidates as $candidateName) {
            foreach ($tlds as $pricingRow) {
                if ($checks >= self::SPIN_MAX_CHECKS) {
                    break 2;
                }

                $checks++;
                $suggestions[] = $this->checkOne($candidateName, $pricingRow);
            }
        }

        return Response::json(['suggestions' => array_values(array_filter($suggestions, static fn (array $s) => $s['checked'] && $s['available']))]);
    }



    /**
     * A handful of common naming patterns (prefix/suffix words,
     * hyphenation) rather than anything AI-driven — enough to give a
     * client real alternatives when their first choice is taken, bounded
     * so it stays a short, fast list rather than an exhaustive search.
     *
     * @return array<int, string>
     */
    private function variationsOf(string $name): array
    {
        $prefixes = ['get', 'my', 'try'];
        $suffixes = ['app', 'hq', 'hub', 'pro', 'online'];

        $variations = [$name];

        foreach ($prefixes as $prefix) {
            $variations[] = $prefix . $name;
        }

        foreach ($suffixes as $suffix) {
            $variations[] = $name . $suffix;
            $variations[] = $name . '-' . $suffix;
        }

        return array_slice(array_unique($variations), 0, 8);
    }

    /** Lightweight JSON endpoint backing the live-availability check on any domain-name field (require_domain product page, domain spinner). */
    public function checkAvailabilityAjax(Request $request): Response
    {
        $domain = $this->normalize((string) $request->query('domain', ''));

        if ($domain === '' || !str_contains($domain, '.')) {
            return Response::json(['checked' => false, 'available' => false, 'message' => 'Enter a valid domain name.']);
        }

        [$name, $tld] = $this->split($domain);
        $pricingRow = $this->domainPricing->findByTld($tld);

        if ($pricingRow === null) {
            return Response::json(['checked' => false, 'available' => false, 'message' => "\"{$tld}\" isn't offered here."]);
        }

        $check = $this->domainService->checkAvailability($domain, (string) $pricingRow['registrar_slug']);

        return Response::json([
            'checked' => $check['success'],
            'available' => $check['available'],
            'message' => $check['message'],
        ]);
    }

    public function addToCart(Request $request): Response
    {
        // Guests may add domains to the session cart; they'll be asked to
        // log in or register at checkout.
        $domain = $this->normalize((string) $request->input('domain', ''));

        if ($domain === '' || !str_contains($domain, '.')) {
            return Response::redirect('/domains/register?error=' . urlencode('Enter a valid domain name.'));
        }

        [$name, $tld] = $this->split($domain);
        $pricingRow = $this->domainPricing->findByTld($tld);

        if ($pricingRow === null) {
            return Response::redirect('/domains/register?error=' . urlencode("\"{$tld}\" isn't offered here."));
        }

        // Re-check server-side — the client's earlier search result is
        // just UI state and could be stale (someone else grabbed the
        // domain in the meantime) or tampered with.
        $check = $this->domainService->checkAvailability($domain, (string) $pricingRow['registrar_slug']);

        if (!$check['success'] || !$check['available']) {
            return Response::redirect('/domains/register?domain=' . urlencode($domain) . '&error=' . urlencode('That domain is no longer available.'));
        }

        $useDefaultNs = (string) $request->input('nameserver_choice', 'default') !== 'custom';
        $nameservers = $useDefaultNs ? $this->domainSettings->defaultNameservers() : $this->customNameserversFrom($request);

        $carrier = $this->db->selectOne("SELECT id FROM products WHERE name = 'Domain Registration' AND status = 'hidden' LIMIT 1");

        if ($carrier === null) {
            return Response::redirect('/domains/register?error=' . urlencode('Domain registration is temporarily unavailable — please contact support.'));
        }

        $this->cart->add((int) $carrier['id'], 'annually', [], 1, [
            'name' => $domain,
            'option' => 'register',
            'ns1' => $nameservers[0] ?? '',
            'ns2' => $nameservers[1] ?? '',
            'ns3' => $nameservers[2] ?? '',
            'ns4' => $nameservers[3] ?? '',
            'ns5' => $nameservers[4] ?? '',
            'ns6' => $nameservers[5] ?? '',
        ]);

        return Response::redirect('/cart');
    }

    /** @return array<int, array{tld: string, domain: string, available: ?bool, price: ?float, checked: bool, message: string}> */
    private function search(string $query): array
    {
        $query = $this->normalize($query);

        if (str_contains($query, '.')) {
            [$name, $tld] = $this->split($query);
            $pricingRow = $this->domainPricing->findByTld($tld);

            if ($pricingRow === null) {
                return [['tld' => $tld, 'domain' => $name . $tld, 'available' => null, 'price' => null, 'checked' => false, 'message' => "\"{$tld}\" isn't offered here."]];
            }

            return [$this->checkOne($name, $pricingRow)];
        }

        // No TLD typed — check the name against every configured TLD, WHMCS-style.
        return array_map(fn (array $pricingRow) => $this->checkOne($query, $pricingRow), $this->domainPricing->all());
    }

    /** @param array<string, mixed> $pricingRow */
    private function checkOne(string $name, array $pricingRow): array
    {
        $domain = $name . $pricingRow['tld'];
        $check = $this->domainService->checkAvailability($domain, (string) $pricingRow['registrar_slug']);

        return [
            'tld' => (string) $pricingRow['tld'],
            'domain' => $domain,
            'available' => $check['success'] ? $check['available'] : null,
            'price' => (float) $pricingRow['register_price'],
            'checked' => $check['success'],
            'message' => $check['success'] ? '' : $check['message'],
        ];
    }

    /** @return array<int, string> */
    private function customNameserversFrom(Request $request): array
    {
        $nameservers = [];

        foreach (['ns1', 'ns2', 'ns3', 'ns4', 'ns5', 'ns6'] as $field) {
            $value = trim((string) $request->input($field, ''));

            if ($value !== '') {
                $nameservers[] = $value;
            }
        }

        return $nameservers;
    }

    private function normalize(string $query): string
    {
        $query = strtolower(trim($query));
        $query = preg_replace('#^https?://#', '', $query) ?? $query;

        return preg_replace('#^www\.#', '', $query) ?? $query;
    }

    /** @return array{0: string, 1: string} [name, .tld] */
    private function split(string $domain): array
    {
        $firstDot = strpos($domain, '.');

        return [substr($domain, 0, $firstDot), '.' . substr($domain, $firstDot + 1)];
    }

    public function transferForm(Request $request): Response
    {
        // Public — see searchForm().
        return Response::html($this->view->render('layouts.client', [
            'title' => 'Transfer a Domain',
            'content' => $this->view->render('domains.transfer', [
                'error' => $request->query('error'),
                'domain' => $request->query('domain', ''),
                'defaultNameservers' => $this->domainSettings->defaultNameservers(),
                'categories' => $this->domainPricing->allByCategory(),
            ]),
        ]));
    }

    public function addTransferToCart(Request $request): Response
    {
        $domain = $this->normalize((string) $request->input('domain', ''));
        $eppCode = trim((string) $request->input('epp_code', ''));

        if ($domain === '' || !str_contains($domain, '.')) {
            return Response::redirect('/domains/transfer?error=' . urlencode('Enter a valid domain name.'));
        }

        if ($eppCode === '') {
            return Response::redirect('/domains/transfer?domain=' . urlencode($domain) . '&error=' . urlencode('EPP/Auth Code is required for transfers.'));
        }

        [$name, $tld] = $this->split($domain);
        $pricingRow = $this->domainPricing->findByTld($tld);

        if ($pricingRow === null) {
            return Response::redirect('/domains/transfer?domain=' . urlencode($domain) . '&error=' . urlencode("\"{$tld}\" isn't offered here."));
        }

        $useDefaultNs = (string) $request->input('nameserver_choice', 'default') !== 'custom';
        $nameservers = $useDefaultNs ? $this->domainSettings->defaultNameservers() : $this->customNameserversFrom($request);

        $carrier = $this->db->selectOne("SELECT id FROM products WHERE name = 'Domain Registration' AND status = 'hidden' LIMIT 1");

        if ($carrier === null) {
            return Response::redirect('/domains/transfer?domain=' . urlencode($domain) . '&error=' . urlencode('Domain transfer is temporarily unavailable — please contact support.'));
        }

        $this->cart->add((int) $carrier['id'], 'annually', [], 1, [
            'name' => $domain,
            'option' => 'transfer',
            'ns1' => $nameservers[0] ?? '',
            'ns2' => $nameservers[1] ?? '',
            'ns3' => $nameservers[2] ?? '',
            'ns4' => $nameservers[3] ?? '',
            'ns5' => $nameservers[4] ?? '',
            'ns6' => $nameservers[5] ?? '',
            'epp_code' => $eppCode,
        ]);

        return Response::redirect('/cart');
    }

    /** @param array<string, mixed> $data */
    private function page(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'Register a New Domain',
            'content' => $content,
        ]));
    }
}
