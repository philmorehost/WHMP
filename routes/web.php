<?php

declare(strict_types=1);

use CodeVault\Container;
use CodeVault\Media\ImageController;
use CodeVault\Reports\AdminDashboardController;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Seo\SeoTags;
use CodeVault\View;

/** @var CodeVault\Router $router */

// WebP image pipeline — /img?path=/assets/uploads/photo.jpg&w=400
$router->get('/img', [ImageController::class, 'serve']);

$router->get('/', function (Request $request, array $params, Container $container): Response {
    // On a reseller's OWN host, "/" must be THEIR storefront.
    //
    // This closure reads product_groups/products/product_pricing and renders the
    // platform's home page with the platform's prices — with no tenant check at
    // all. So a store reached at its own domain showed the reseller's brand (that
    // comes from CurrentReseller, which IS host-aware) above OUR catalogue at OUR
    // prices: the one combination a white-label store must never show, because the
    // customer cannot tell they are being quoted the platform's retail.
    //
    // So a store gets its OWN home page (StorefrontHome): hero, domain search, a
    // few featured plans at the store's retail prices, and its categories as
    // compact cards. It used to redirect to /store, which printed the entire
    // catalogue on one very long page; the full list now lives under SERVICES in
    // the menu (/store?group_id=N). Every figure the home page shows comes from
    // StorefrontCatalogue, which prices through the same retail engine as the cart.
    $tenant = $container->make(\CodeVault\Reseller\CurrentReseller::class);

    if ($tenant->get() !== null) {
        return $container->make(\CodeVault\Reseller\StorefrontHome::class)->index($request);
    }

    /** @var View $view */
    $view = $container->make(View::class);
    /** @var SeoTags $seo */
    $seo = $container->make(SeoTags::class);
    /** @var CodeVault\Database $db */
    $db = $container->make(CodeVault\Database::class);
    /** @var \CodeVault\Settings\SettingsRepository $settings */
    $settings = $container->make(\CodeVault\Settings\SettingsRepository::class);
    $whatsappNumber = trim((string) $settings->get('company.whatsapp', ''));

    $groups = $db->select("SELECT * FROM product_groups ORDER BY id ASC");
    $productGroups = [];

    foreach ($groups as $group) {
        $groupId = (int) $group['id'];
        $products = $db->select("
            SELECT p.*, pp.price, pp.billing_cycle 
            FROM products p 
            LEFT JOIN product_pricing pp ON p.id = pp.product_id AND pp.billing_cycle = 'monthly'
            WHERE p.product_group_id = ? AND p.status = 'active'
            ORDER BY p.id ASC
        ", [$groupId]);

        foreach ($products as &$prod) {
            if ($prod['price'] === null) {
                $anyPricing = $db->selectOne("SELECT price, billing_cycle FROM product_pricing WHERE product_id = ? LIMIT 1", [(int) $prod['id']]);
                if ($anyPricing !== null) {
                    $prod['price'] = $anyPricing['price'];
                    $prod['billing_cycle'] = $anyPricing['billing_cycle'];
                } else {
                    $prod['price'] = 0.00;
                    $prod['billing_cycle'] = 'monthly';
                }
            }
        }

        if (count($products) > 0) {
            $productGroups[] = [
                'group' => $group,
                'products' => $products,
            ];
        }
    }

    $content = $view->render('pages.home', [
        'productGroups' => $productGroups,
        'whatsappNumber' => $whatsappNumber,
    ]);

    return Response::html($view->render('layouts.client', [
        'title' => 'Web Hosting & Domains',
        'content' => $content,
        'canonicalUrl' => $seo->canonicalUrl('/'),
        'metaDescription' => 'Reliable web hosting, domain registration, and support for your business.',
        'jsonLd' => [$seo->organization()],
    ]));
});

$router->get('/deals', function (Request $request, array $params, Container $container): Response {
    /** @var View $view */
    $view = $container->make(View::class);
    /** @var CodeVault\Database $db */
    $db = $container->make(CodeVault\Database::class);
    /** @var \CodeVault\Settings\SettingsRepository $settings */
    $settings = $container->make(\CodeVault\Settings\SettingsRepository::class);
    $whatsappNumber = trim((string) $settings->get('company.whatsapp', ''));

    // Deals are a SITE's deals. On a reseller's host this lists only that store's
    // own codes and links to the store's own WhatsApp — never the platform's
    // promotions or the platform's number, which belong to a different business.
    $tenant = $container->make(\CodeVault\Reseller\CurrentReseller::class);
    $store = $tenant->get();

    if ($store !== null) {
        $ownerPhone = null;

        if (!\CodeVault\Reseller\ResellerChat::isConfigured($store) && (int) ($store['client_id'] ?? 0) > 0) {
            $owner = $container->make(\CodeVault\Clients\ClientRepository::class)->find((int) $store['client_id']);
            $ownerPhone = $owner === null ? null : (string) ($owner['phone'] ?? '');
        }

        $whatsappNumber = (string) (\CodeVault\Reseller\ResellerChat::whatsappDigitsFor($store, $ownerPhone) ?? '');
    }

    $promotions = $db->select("
        SELECT * FROM promotions
        WHERE status = 'active'
          AND " . ($store !== null ? 'reseller_id = ?' : 'reseller_id IS NULL') . "
          AND (starts_at IS NULL OR starts_at <= CURRENT_DATE())
          AND (expires_at IS NULL OR expires_at >= CURRENT_DATE())
        ORDER BY id DESC
    ", $store !== null ? [(int) $store['id']] : []);

    $content = $view->render('pages.deals', [
        'promotions' => $promotions,
        'whatsappNumber' => $whatsappNumber,
    ]);

    return Response::html($view->render('layouts.client', [
        'title' => 'New Deals & Promotions',
        'content' => $content,
    ]));
});

// Terms of Service link target. The actual page lives on the company's
// primary website; an admin sets its URL under Configuration → Theme. If
// unset, we show a short notice rather than a broken 404 (which is what
// prompted this route — the store/footer link to /terms).
$router->get('/terms', function (Request $request, array $params, Container $container): Response {
    /** @var CodeVault\Theme\ThemeSettings $theme */
    $theme = $container->make(CodeVault\Theme\ThemeSettings::class);
    $termsUrl = $theme->termsUrl();

    if ($termsUrl !== null) {
        return Response::redirect($termsUrl);
    }

    /** @var View $view */
    $view = $container->make(View::class);
    $content = '<div class="cv-card" style="max-width:40rem;margin:2rem auto;text-align:center;">'
        . '<h1 class="cv-card__title">Terms of Service</h1>'
        . '<p style="color:var(--cv-text-secondary);">Our Terms of Service haven\'t been published here yet. Please contact us if you need a copy.</p>'
        . '<p><a class="cv-btn cv-btn--secondary" href="/">Back to home</a></p></div>';

    return Response::html($view->render('layouts.client', [
        'title' => 'Terms of Service',
        'content' => $content,
    ]));
});

$router->get('/admin', [AdminDashboardController::class, 'index']);
