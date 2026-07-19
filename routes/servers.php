<?php

declare(strict_types=1);

use CodeVault\Provisioning\ServerController;

/** @var CodeVault\Router $router */

$router->get('/admin/servers', [ServerController::class, 'index']);
$router->post('/admin/servers', [ServerController::class, 'store']);
$router->get('/admin/servers/{id}/edit', [ServerController::class, 'editForm']);
$router->post('/admin/servers/{id}', [ServerController::class, 'update']);
$router->post('/admin/servers/{id}/toggle', [ServerController::class, 'toggle']);
$router->post('/admin/servers/{id}/delete', [ServerController::class, 'destroy']);
$router->post('/admin/server-groups', [ServerController::class, 'storeGroup']);
$router->post('/admin/server-groups/{id}/delete', [ServerController::class, 'destroyGroup']);
