<?php

declare(strict_types=1);

use CodeVault\Billing\CurrencyController;
use CodeVault\Billing\CurrencySwitchController;

/** @var CodeVault\Router $router */

$router->get('/admin/currencies', [CurrencyController::class, 'index']);
$router->post('/admin/currencies', [CurrencyController::class, 'store']);
$router->post('/admin/currencies/{id}', [CurrencyController::class, 'update']);
$router->post('/admin/currencies/{id}/default', [CurrencyController::class, 'setDefault']);
$router->post('/admin/currencies/{id}/delete', [CurrencyController::class, 'destroy']);

$router->post('/currency', [CurrencySwitchController::class, 'select']);
