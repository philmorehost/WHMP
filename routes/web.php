<?php

declare(strict_types=1);

use CodeVault\Container;
use CodeVault\Reports\AdminDashboardController;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Seo\SeoTags;
use CodeVault\View;

/** @var CodeVault\Router $router */

$router->get('/', function (Request $request, array $params, Container $container): Response {
    /** @var View $view */
    $view = $container->make(View::class);
    /** @var SeoTags $seo */
    $seo = $container->make(SeoTags::class);
    /** @var CodeVault\Database $db */
    $db = $container->make(CodeVault\Database::class);

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
    ]);

    return Response::html($view->render('layouts.client', [
        'title' => 'CodeVault — Web Hosting & Domains',
        'content' => $content,
        'canonicalUrl' => $seo->canonicalUrl('/'),
        'metaDescription' => 'CodeVault provides reliable web hosting, domain registration, and support for your business.',
        'jsonLd' => [$seo->organization()],
    ]));
});

$router->get('/deals', function (Request $request, array $params, Container $container): Response {
    /** @var View $view */
    $view = $container->make(View::class);
    /** @var CodeVault\Database $db */
    $db = $container->make(CodeVault\Database::class);

    $promotions = $db->select("
        SELECT * FROM promotions 
        WHERE status = 'active' 
          AND (starts_at IS NULL OR starts_at <= CURRENT_DATE()) 
          AND (expires_at IS NULL OR expires_at >= CURRENT_DATE())
        ORDER BY id DESC
    ");

    $content = $view->render('pages.deals', [
        'promotions' => $promotions,
    ]);

    return Response::html($view->render('layouts.client', [
        'title' => 'New Deals & Promotions',
        'content' => $content,
    ]));
});

$router->get('/admin', [AdminDashboardController::class, 'index']);
