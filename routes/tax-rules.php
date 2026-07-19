<?php

declare(strict_types=1);

use CodeVault\Billing\TaxRuleController;

/** @var CodeVault\Router $router */

$router->get('/admin/tax-rules', [TaxRuleController::class, 'index']);
$router->post('/admin/tax-rules', [TaxRuleController::class, 'store']);
$router->post('/admin/tax-rules/{id}/delete', [TaxRuleController::class, 'destroy']);
$router->post('/admin/tax-rules/seller-country', [TaxRuleController::class, 'saveSellerCountry']);
