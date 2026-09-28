<?php

declare(strict_types=1);

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

// Admin side: the discounts resellers get, and the keys issued to them.
$router->get('/admin/resellers', [AdminResellerController::class, 'index']);
$router->post('/admin/resellers/discounts', [AdminResellerController::class, 'saveDiscounts']);
$router->post('/admin/resellers/{clientId}/toggle', [AdminResellerController::class, 'toggle']);
$router->get('/admin/resellers/docs', [AdminResellerController::class, 'docs']);
