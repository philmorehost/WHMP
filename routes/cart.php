<?php

declare(strict_types=1);

use CodeVault\Cart\CheckoutController;

/** @var CodeVault\Router $router */

$router->get('/store', [CheckoutController::class, 'store']);
$router->get('/store/{id}', [CheckoutController::class, 'product']);
$router->post('/cart/add', [CheckoutController::class, 'addToCart']);
$router->get('/cart', [CheckoutController::class, 'cart']);
$router->post('/cart/remove/{index}', [CheckoutController::class, 'removeFromCart']);
$router->post('/cart/apply-promo', [CheckoutController::class, 'applyPromo']);
$router->post('/cart/remove-promo', [CheckoutController::class, 'removePromo']);
$router->post('/cart/save-email', [CheckoutController::class, 'saveEmail']);
$router->post('/cart/checkout', [CheckoutController::class, 'checkout']);
