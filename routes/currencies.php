<?php

declare(strict_types=1);

use CodeVault\Billing\CurrencyController;
use CodeVault\Billing\CurrencySwitchController;

/** @var CodeVault\Router $router */

$router->get('/admin/currencies', [CurrencyController::class, 'index']);
$router->post('/admin/currencies', [CurrencyController::class, 'store']);
$router->post('/admin/currencies/{id}', [CurrencyController::class, 'update']);
$router->post('/admin/currencies/{id}/default', [CurrencyController::class, 'setDefault']);
$router->post('/admin/currencies/{id}/pricing', [CurrencyController::class, 'setPricing']);
$router->post('/admin/currencies/{id}/delete', [CurrencyController::class, 'destroy']);

$router->post('/currency', [CurrencySwitchController::class, 'select']);

// The deliberate counterpart to the browse toggle above: change the ACCOUNT's
// currency, re-denominating every live amount. A separate endpoint because it
// is a money operation, not a display preference — see the controller.
$router->post('/currency/account', [CurrencySwitchController::class, 'applyToAccount']);
