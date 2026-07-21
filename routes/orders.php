<?php

declare(strict_types=1);

use CodeVault\Billing\OrderController;

/** @var CodeVault\Router $router */

$router->get('/admin/orders', [OrderController::class, 'index']);
$router->get('/admin/orders/{id}', [OrderController::class, 'show']);
$router->post('/admin/orders/{id}/accept', [OrderController::class, 'accept']);
$router->post('/admin/orders/{id}/cancel', [OrderController::class, 'cancel']);
$router->post('/admin/orders/{id}/delete', [OrderController::class, 'destroy']);
