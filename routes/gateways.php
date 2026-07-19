<?php

declare(strict_types=1);

use CodeVault\Billing\GatewayController;

/** @var CodeVault\Router $router */

$router->get('/admin/gateways', [GatewayController::class, 'index']);
$router->post('/admin/gateways/{slug}/toggle', [GatewayController::class, 'toggle']);
$router->post('/admin/gateways/{slug}/config', [GatewayController::class, 'updateConfig']);
