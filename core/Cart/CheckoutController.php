<?php

declare(strict_types=1);

namespace CodeVault\Cart;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencySelection;
use CodeVault\Billing\CurrencyService;
use CodeVault\Cache\Cache;
use CodeVault\Catalog\BillingCycle;
use CodeVault\Catalog\ConfigurableOptionGroupRepository;
use CodeVault\Catalog\ConfigurableOptionRepository;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Domains\DomainPricingRepository;
use CodeVault\Domains\DomainService;
use CodeVault\Domains\DomainSettings;
use CodeVault\Localization\LanguageRepository;
use CodeVault\Localization\LanguageSelection;
use CodeVault\Localization\LocalizationService;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Seo\SeoTags;
use CodeVault\View;

final class CheckoutController
{
    public function __construct(
        private readonly View $view,
        private readonly ProductGroupRepository $groups,
        private readonly ProductRepository $products,
        private readonly ProductPricingRepository $pricing,
        private readonly ConfigurableOptionGroupRepository $optionGroups,
        private readonly ConfigurableOptionRepository $options,
        private readonly Cart $cart,
        private readonly CartService $cartService,
        private readonly CheckoutService $checkout,
        private readonly ClientAuthGuard $guard,
        private readonly SeoTags $seo,
        private readonly Cache $cache,
        private readonly CurrencyRepository $currencies,
        private readonly CurrencyService $currency,
        private readonly CurrencySelection $currencySelection,
        private readonly LanguageRepository $languages,
        private readonly LocalizationService $localization,
        private readonly LanguageSelection $languageSelection,
        private readonly DomainSettings $domainSettings,
        private readonly DomainPricingRepository $domainPricing,
        private readonly DomainService $domainService
    ) {
    }

    public function store(Request $request): Response
    {
        $groups = $this->cache->remember('store:index_v2', 60, function () {
            $groups = $this->groups->all();
            $productsByGroup = $this->products->allGroupedByGroup();
            $db = \CodeVault\Support\App::container()->make(\CodeVault\Database::class);

            foreach ($groups as &$group) {
                $prods = $productsByGroup[(int) $group['id']] ?? [];
                foreach ($prods as &$prod) {
                    $pricingRows = $db->select("SELECT price, billing_cycle FROM product_pricing WHERE product_id = ? ORDER BY price ASC", [(int) $prod['id']]);
                    if ($pricingRows !== []) {
                        $monthlyRow = array_values(array_filter($pricingRows, fn($r) => $r['billing_cycle'] === 'monthly'))[0] ?? $pricingRows[0];
                        $prod['starting_price'] = (float) $monthlyRow['price'];
                        $prod['starting_cycle'] = (string) $monthlyRow['billing_cycle'];
                    } else {
                        $prod['starting_price'] = 0.00;
                        $prod['starting_cycle'] = 'monthly';
                    }
                }
                unset($prod);
                $group['products'] = $prods;
            }
            unset($group);

            return $groups;
        });

        $client = $this->guard->currentClient();
        $currency = $this->currency->resolveEffective($client, $this->currencySelection->get());

        $selectedGroupId = $request->query('group_id') !== null ? (int) $request->query('group_id') : null;
        if ($selectedGroupId !== null) {
            $groups = array_filter($groups, static fn ($g) => (int) $g['id'] === $selectedGroupId);
        }

        return $this->page('cart.store', ['groups' => $groups, 'currency' => $currency], [
            'canonicalUrl' => $this->seo->canonicalUrl('/store'),
            'metaDescription' => 'Browse web hosting plans, domain registration, and add-ons built for reliability and fast support.',
            'currencies' => $this->currencies->all(),
            'selectedCurrency' => $currency,
        ], $client);
    }

    public function product(Request $request, array $params): Response
    {
        $product = $this->products->find((int) $params['id']);

        if ($product === null || $product['status'] !== 'active') {
            return \CodeVault\Router::notFound(\CodeVault\Support\App::container(), $request->path());
        }

        $productOptionGroups = $this->optionGroups->forProduct((int) $product['id']);

        foreach ($productOptionGroups as &$og) {
            $og['options'] = $this->options->forGroup((int) $og['id']);
        }
        unset($og);

        $pricing = $this->pricing->forProduct((int) $product['id']);
        $cheapest = $pricing === [] ? 0.0 : min(array_map(static fn (array $row) => (float) $row['price'], $pricing));
        $url = $this->seo->canonicalUrl("/store/{$product['id']}");
        $client = $this->guard->currentClient();
        $currency = $this->currency->resolveEffective($client, $this->currencySelection->get());

        $db = \CodeVault\Support\App::container()->make(\CodeVault\Database::class);
        $customFields = $db->select("SELECT * FROM custom_fields WHERE field_for = 'product' AND (product_id IS NULL OR product_id = ?) ORDER BY sort_order ASC", [$product['id']]);

        return $this->page('cart.product', [
            'product' => $product,
            'pricing' => $pricing,
            'cycles' => BillingCycle::labels(),
            'optionGroups' => $productOptionGroups,
            'currency' => $currency,
            'customFields' => $customFields,
            'defaultNameservers' => $this->domainSettings->defaultNameservers(),
            'domainTlds' => array_column($this->domainPricing->all(), 'tld'),
            'error' => $request->query('error'),
        ], [
            'title' => "{$product['name']}",
            'canonicalUrl' => $url,
            'metaDescription' => mb_strimwidth(strip_tags((string) ($product['description'] ?? $product['name'])), 0, 160, '...'),
            'currencies' => $this->currencies->all(),
            'selectedCurrency' => $currency,
            'jsonLd' => [
                $this->seo->organization(),
                $this->seo->product($product['name'], (string) ($product['description'] ?? ''), $cheapest, $url, $product['stock_quantity'] === null || (int) $product['stock_quantity'] > 0),
                $this->seo->breadcrumbList([
                    ['name' => 'Home', 'url' => $this->seo->canonicalUrl('/')],
                    ['name' => 'Store', 'url' => $this->seo->canonicalUrl('/store')],
                    ['name' => $product['name'], 'url' => $url],
                ]),
            ],
        ], $client);
    }

    public function addToCart(Request $request): Response
    {
        $productId = (int) $request->input('product_id', 0);
        $cycle = (string) $request->input('billing_cycle', '');
        $quantity = max(1, (int) $request->input('quantity', 1));

        $selectedOptions = [];

        foreach ((array) $request->input('option', []) as $groupId => $optionId) {
            if ($optionId !== '') {
                $selectedOptions[(int) $groupId] = (int) $optionId;
            }
        }

        $domainOptions = null;
        $domainOption = (string) $request->input('domain_option');
        if ($domainOption !== '') {
            $domainName = strtolower(trim((string) $request->input('domain_name', '')));
            $domainTld = strtolower(trim((string) $request->input('domain_tld', '')));

            if ($domainOption === 'existing') {
                $fullDomain = $domainName;
            } else {
                // Strip anything from the first dot onward before appending the
                // selected extension. The field is meant for just the name
                // ("yourbusiness"), but a client primed by the standalone domain
                // search page often types the full domain anyway
                // ("example.com") — without this, that became
                // "example.com.com" (or whatever TLD happened to be selected),
                // silently different from the domain the live availability
                // check on this same page had actually confirmed.
                $dotPos = strpos($domainName, '.');
                $nameOnly = $dotPos !== false ? substr($domainName, 0, $dotPos) : $domainName;
                $fullDomain = $nameOnly . $domainTld;
            }

            if ($domainOption === 'register' && $fullDomain !== '' && str_contains($fullDomain, '.')) {
                $tld = '.' . substr($fullDomain, strpos($fullDomain, '.') + 1);
                $pricingRow = $this->domainPricing->findByTld($tld);

                if ($pricingRow === null) {
                    return Response::redirect("/store/{$productId}?error=" . urlencode("\"{$tld}\" isn't offered here."));
                }

                // Re-check server-side — the client's earlier live-availability
                // check on this page is just UI state and could be stale
                // (someone else registered it) or tampered with, same
                // reasoning as the standalone domain search page's own
                // addToCart() (DomainRegistrationController).
                $check = $this->domainService->checkAvailability($fullDomain, (string) $pricingRow['registrar_slug']);

                if (!$check['success'] || !$check['available']) {
                    return Response::redirect("/store/{$productId}?error=" . urlencode('That domain is no longer available.'));
                }
            }

            $domainOptions = [
                'option' => $domainOption,
                'name' => $fullDomain,
                'ns1' => trim((string) $request->input('ns1', '')),
                'ns2' => trim((string) $request->input('ns2', '')),
                'ns3' => trim((string) $request->input('ns3', '')),
                'ns4' => trim((string) $request->input('ns4', '')),
                'ns5' => trim((string) $request->input('ns5', '')),
                'ns6' => trim((string) $request->input('ns6', '')),
            ];
        }

        $serverOptions = null;
        if ((string) $request->input('hostname') !== '') {
            $serverOptions = [
                'hostname' => trim((string) $request->input('hostname')),
                'root_password' => trim((string) $request->input('root_password')),
            ];
        }

        $customFieldsInput = (array) $request->input('custom_field', []);

        if ($productId > 0 && $cycle !== '') {
            $this->cart->add($productId, $cycle, $selectedOptions, $quantity, $domainOptions, $serverOptions, $customFieldsInput);
        }

        return Response::redirect('/cart');
    }

    public function cart(Request $request): Response
    {
        $inCart = array_map(static fn (array $item) => $item['product_id'], $this->cart->items());
        $client = $this->guard->currentClient();
        $currency = $this->currency->resolveEffective($client, $this->currencySelection->get());

        return $this->page('cart.cart', [
            'priced' => $this->cartService->priced(),
            'loggedIn' => $this->guard->check(),
            'upsells' => $this->products->upsellProducts($inCart),
            'currency' => $currency,
        ], [
            'currencies' => $this->currencies->all(),
            'selectedCurrency' => $currency,
        ], $client);
    }

    public function removeFromCart(Request $request, array $params): Response
    {
        $this->cart->removeAt((int) $params['index']);

        return Response::redirect('/cart');
    }

    public function applyPromo(Request $request): Response
    {
        $code = trim((string) $request->input('promo_code', ''));

        if ($code !== '') {
            $this->cart->setPromoCode($code);
        }

        return Response::redirect('/cart');
    }

    public function removePromo(Request $request): Response
    {
        $this->cart->clearPromoCode();

        return Response::redirect('/cart');
    }

    public function checkout(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        // Require client contact details (street address, city, postcode, phone) for order completion
        if (empty($client['address1']) || empty($client['city']) || empty($client['postcode']) || empty($client['phone'])) {
            $inCart = array_map(static fn (array $item) => $item['product_id'], $this->cart->items());
            $currency = $this->currency->resolveEffective($client, $this->currencySelection->get());

            return $this->page('cart.cart', [
                'priced' => $this->cartService->priced(),
                'loggedIn' => true,
                'upsells' => $this->products->upsellProducts($inCart),
                'error' => 'Please complete your street address, city, postcode, and phone number in your account profile before completing your order. <a href="/client/account" style="text-decoration:underline;">Update Profile</a>',
                'currency' => $currency,
            ], [
                'currencies' => $this->currencies->all(),
                'selectedCurrency' => $currency,
            ], $client);
        }

        $result = $this->checkout->placeOrder((int) $client['id']);

        if (!$result['success']) {
            $inCart = array_map(static fn (array $item) => $item['product_id'], $this->cart->items());
            $currency = $this->currency->resolveEffective($client, $this->currencySelection->get());

            return $this->page('cart.cart', [
                'priced' => $this->cartService->priced(),
                'loggedIn' => true,
                'upsells' => $this->products->upsellProducts($inCart),
                'error' => $result['error'],
                'currency' => $currency,
            ], [
                'currencies' => $this->currencies->all(),
                'selectedCurrency' => $currency,
            ], $client);
        }

        return Response::redirect("/client/invoices/{$result['invoiceId']}");
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $seoOverrides
     * @param array<string, mixed>|null $client
     */
    private function page(string $template, array $data, array $seoOverrides = [], ?array $client = null): Response
    {
        $language = $this->localization->resolveEffective($client, $this->languageSelection->get());
        $t = $this->localization->translationFor($language);
        $data['t'] = $t;

        // One money formatter for every storefront view, delegating to
        // CurrencyService::format(). Stored prices are authoritative in the
        // base currency and converted only for display, so each view must
        // apply the selected currency's rate — printing the selected
        // symbol against an unconverted base amount reads as a wildly
        // wrong price, and hand-rolled per-view conversions had drifted
        // apart. Views get $money and never touch exchange_rate directly.
        if (isset($data['currency']) && is_array($data['currency'])) {
            $currency = $data['currency'];
            $data['money'] = fn (float $baseAmount): string => $this->currency->format($baseAmount, $currency);
        }

        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.client', array_merge([
            'title' => 'Store',
            'content' => $content,
            'jsonLd' => [$this->seo->organization()],
            't' => $t,
            'languages' => $this->languages->active(),
        ], $seoOverrides)));
    }
}
