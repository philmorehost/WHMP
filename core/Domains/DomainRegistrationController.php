<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Billing\CurrencySelection;
use CodeVault\Billing\CurrencyService;
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
        private readonly \CodeVault\Ai\AiSettings $aiSettings,
        private readonly \CodeVault\Activity\ActivityLogger $activity,
        private readonly CurrencyService $currencyService,
        private readonly CurrencySelection $currencySelection
    ) {
    }

    public function searchForm(Request $request): Response
    {
        // Public — prospective clients can search/browse TLDs without an
        // account (the session cart works for guests; login is only required
        // at checkout, WHMCS-style).
        $query = trim((string) $request->query('domain', (string) $request->query('domain_search', '')));

        // Same currency resolution as the live-availability AJAX check
        // (checkAvailabilityAjax()) and the rest of the storefront
        // (CheckoutController::page()) — a visitor who hasn't logged in yet
        // still gets their session-selected currency honoured here.
        $client = $this->guard->currentClient();
        $currency = $this->currencyService->resolveEffective($client, $this->currencySelection->get());

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
            'currency' => $currency,
            'money' => fn (float $baseAmount): string => $this->currencyService->format($baseAmount, $currency),
        ]);
    }

    /**
     * Domain Spinner — given a base name, generates common
     * prefix/suffix/hyphenation variations against whichever TLDs the admin
     * opted into the spinner (DomainPricingRepository::spinnerEnabled(), a
     * deliberately separate/usually-smaller list than every TLD offered for
     * direct registration). Capped at SPIN_MAX_CHECKS candidates for the same
     * reason — a spin is a suggestion tool, not an exhaustive search.
     *
     * Returns the candidate name/TLD/price list unchecked — it used to check
     * every candidate's live availability here, sequentially, before
     * responding: up to 15 registrar API round-trips in a row on a single
     * request, which is exactly the kind of blocking wait that made the
     * "Suggested Alternatives" box (which auto-fires on every domains/register
     * page load) sit on "Spinning..." for a long time. The browser now runs
     * those same checks itself against /domains/availability, one fetch per
     * candidate, all in parallel — the checks still happen, they just aren't
     * serialized behind a single PHP request anymore.
     */
    private const SPIN_MAX_CHECKS = 15;

    public function spin(Request $request): Response
    {
        $name = $this->normalize((string) $request->query('name', ''));
        $name = preg_replace('/[^a-z0-9-]/', '', $name) ?? '';

        if ($name === '') {
            return Response::json(['candidates' => []]);
        }

        $tlds = $this->domainPricing->spinnerEnabled();

        if ($tlds === []) {
            // This response reaches a real visitor's browser (this endpoint
            // needs no auth), so it says nothing about the admin-side cause —
            // "an admin can turn this on in Domain Pricing" told a customer to
            // go be a site operator. The actual fix for that cause lives on
            // the admin's own Domain Pricing page (a banner there, with a
            // one-click "Enable Spinner for All TLDs" action, points them at
            // it directly instead).
            return Response::json(['candidates' => [], 'message' => 'No alternative names available right now — try a different search.']);
        }

        // Rule-based variations as a reliable, zero-cost fallback.
        $candidateNames = array_values(array_unique($this->variationsOf($name)));
        $candidates = [];
        $checks = 0;

        foreach ($candidateNames as $candidateName) {
            foreach ($tlds as $pricingRow) {
                if ($checks >= self::SPIN_MAX_CHECKS) {
                    break 2;
                }

                $checks++;
                $candidates[] = ['domain' => $candidateName . $pricingRow['tld'], 'price' => (float) $pricingRow['register_price']];
            }
        }

        return Response::json(['candidates' => $candidates]);
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

        $client = $this->guard->currentClient();
        $container = \CodeVault\Support\App::container();
        $currencySelection = $container->make(\CodeVault\Billing\CurrencySelection::class);
        $currencyService = $container->make(\CodeVault\Billing\CurrencyService::class);
        $currency = $currencyService->resolveEffective($client, $currencySelection->get());

        $price = (float) $pricingRow['register_price'];
        $transferPrice = (float) $pricingRow['transfer_price'];

        // format() applies the base-currency-is-1.0 rule; the raw exchange_rate
        // column priced a ₦7,500 domain at ₦11,175,000 on an install whose base
        // currency still carried its pre-promotion rate.
        $formattedPrice = $currencyService->format($price, $currency);
        $formattedTransferPrice = $currencyService->format($transferPrice, $currency);

        return Response::json([
            'checked' => $check['success'],
            'available' => $check['available'],
            'message' => $check['message'],
            'price' => $price,
            'transfer_price' => $transferPrice,
            'formatted_price' => $formattedPrice,
            'formatted_transfer_price' => $formattedTransferPrice,
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

        $this->cart->add($this->carrierProductId(), 'annually', [], 1, [
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

    /**
     * The TLD/price candidate list for a query — deliberately no live
     * registrar call here. This used to check every candidate's availability
     * synchronously before the search page could respond at all: a name
     * typed with no TLD fanned out into one live API call per configured
     * TLD, one after another, so the whole page sat blank until the slowest
     * registrar answered. The page now renders this list immediately and the
     * browser checks each domain's real availability itself, in parallel,
     * against /domains/availability (see the domain-search-page block in
     * app.js) — results fill in as each check resolves instead of the page
     * waiting on all of them.
     *
     * @return array<int, array{tld: string, domain: string, price: float, offered: bool, message: string}>
     */
    private function search(string $query): array
    {
        $query = $this->normalize($query);

        if (str_contains($query, '.')) {
            [$name, $tld] = $this->split($query);
            $pricingRow = $this->domainPricing->findByTld($tld);

            if ($pricingRow === null) {
                return [['tld' => $tld, 'domain' => $name . $tld, 'price' => 0.0, 'offered' => false, 'message' => "\"{$tld}\" isn't offered here."]];
            }

            return [$this->candidate($name, $pricingRow)];
        }

        // No TLD typed — list the name against every configured TLD, WHMCS-style.
        return array_map(fn (array $pricingRow) => $this->candidate($query, $pricingRow), $this->domainPricing->all());
    }

    /** @param array<string, mixed> $pricingRow */
    private function candidate(string $name, array $pricingRow): array
    {
        return [
            'tld' => (string) $pricingRow['tld'],
            'domain' => $name . $pricingRow['tld'],
            'price' => (float) $pricingRow['register_price'],
            'offered' => true,
            'message' => '',
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

    /**
     * The hidden, $0 "carrier" product every domain-only cart line rides on
     * (see the class docblock). Moved onto DomainService — AdminOrderController
     * needs the exact same lookup for an admin-created standalone domain
     * order, and having two independent find-or-create implementations risks
     * them drifting into two different carrier products.
     */
    private function carrierProductId(): int
    {
        return $this->domainService->carrierProductId();
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

        $this->cart->add($this->carrierProductId(), 'annually', [], 1, [
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
