<?php

declare(strict_types=1);

use CodeVault\Reseller\AdminResellerBillingController;
use CodeVault\Reseller\AdminResellerController;
use CodeVault\Reseller\ClientResellerController;

/** @var CodeVault\Router $router */

// The client-facing Reseller Area. The whole programme hangs off this pair of
// steps: request a key (created DISABLED), then submit the domain you resell
// from — which is the only thing that switches the key on.
$router->get('/client/reseller', [ClientResellerController::class, 'index']);
$router->post('/client/reseller/key', [ClientResellerController::class, 'requestKey']);
$router->post('/client/reseller/activate', [ClientResellerController::class, 'activate']);
$router->post('/client/reseller/rotate', [ClientResellerController::class, 'rotate']);
$router->get('/client/reseller/docs', [ClientResellerController::class, 'docs']);

// The white-label store. Claiming a domain and proving control of it are
// separate steps on purpose: nothing is served on a claimant's domain until
// DNS shows they control it.
$router->get('/client/reseller/store', [ClientResellerController::class, 'store']);
$router->post('/client/reseller/store', [ClientResellerController::class, 'openStore']);
$router->post('/client/reseller/store/brand', [ClientResellerController::class, 'saveStoreBrand']);
$router->post('/client/reseller/store/domain', [ClientResellerController::class, 'claimStoreDomain']);
$router->post('/client/reseller/store/verify', [ClientResellerController::class, 'verifyStoreDomain']);

// What the reseller's own customers will pay: a store-wide markup plus optional
// per-cycle and per-TLD overrides. Preview only until checkout charges retail.
$router->get('/client/reseller/prices', [ClientResellerController::class, 'prices']);
$router->post('/client/reseller/prices', [ClientResellerController::class, 'savePrices']);

// Admin side: the discounts resellers get, and the keys issued to them.
$router->get('/admin/resellers', [AdminResellerController::class, 'index']);
$router->post('/admin/resellers/discounts', [AdminResellerController::class, 'saveDiscounts']);
$router->post('/admin/resellers/{clientId}/toggle', [AdminResellerController::class, 'toggle']);
$router->get('/admin/resellers/docs', [AdminResellerController::class, 'docs']);

// What the stores owe us, and how it is billed. Registered BEFORE the
// parameterised store routes below so these literal paths cannot be read as a
// client id. "Bill now" is safe to press twice — an order carries the id of the
// invoice that billed it, so idempotency is a property of the data.
$router->get('/admin/resellers/billing', [AdminResellerBillingController::class, 'index']);
$router->post('/admin/resellers/billing/settings', [AdminResellerBillingController::class, 'saveSettings']);
$router->post('/admin/resellers/billing/run', [AdminResellerBillingController::class, 'runNow']);

// Store management, addressed by client id so a client who has no store yet is
// still reachable (that is exactly when an admin needs to look).
$router->get('/admin/resellers/{clientId}/store', [AdminResellerController::class, 'store']);
$router->post('/admin/resellers/{clientId}/store', [AdminResellerController::class, 'openStore']);
$router->post('/admin/resellers/{clientId}/store/brand', [AdminResellerController::class, 'saveStoreBrand']);
$router->post('/admin/resellers/{clientId}/store/domain', [AdminResellerController::class, 'claimStoreDomain']);
$router->post('/admin/resellers/{clientId}/store/verify', [AdminResellerController::class, 'verifyStoreDomain']);
// Forcing a domain verification (or taking it back) when DNS cannot be queried
// the way DomainVerifier needs. Recorded as 'manual' so an override never looks
// like a real proof.
$router->post('/admin/resellers/{clientId}/store/domain/override', [AdminResellerController::class, 'overrideStoreDomain']);
$router->post('/admin/resellers/{clientId}/store/status', [AdminResellerController::class, 'setStoreStatus']);
