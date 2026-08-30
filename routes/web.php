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

$router->get('/db-debug', function (Request $request, array $params, Container $container): Response {
    /** @var CodeVault\Database $db */
    $db = $container->make(CodeVault\Database::class);
    
    $currencies = $db->select("SELECT * FROM currencies");
    $clients = $db->select("SELECT id, currency_id FROM clients LIMIT 5");
    $invoices = $db->select("SELECT id, subtotal, total, currency_id, currency_rate FROM invoices LIMIT 5");
    $pricing = $db->select("SELECT * FROM product_pricing LIMIT 5");
    $services = $db->select("SELECT id, amount, billing_cycle FROM services LIMIT 5");

    return Response::json([
        'currencies' => $currencies,
        'clients' => $clients,
        'invoices' => $invoices,
        'pricing' => $pricing,
        'services' => $services,
    ]);
});

$router->get('/', function (Request $request, array $params, Container $container): Response {
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

    $promotions = $db->select("
        SELECT * FROM promotions 
        WHERE status = 'active' 
          AND (starts_at IS NULL OR starts_at <= CURRENT_DATE()) 
          AND (expires_at IS NULL OR expires_at >= CURRENT_DATE())
        ORDER BY id DESC
    ");

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
