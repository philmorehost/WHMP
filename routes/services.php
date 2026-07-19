<?php

declare(strict_types=1);

use CodeVault\Billing\ServiceController;

/** @var CodeVault\Router $router */

$router->get('/admin/services', [ServiceController::class, 'index']);
$router->get('/admin/services/{id}', [ServiceController::class, 'show']);
$router->post('/admin/services/{id}/upgrade', [ServiceController::class, 'upgrade']);
$router->post('/admin/services/{id}/suspend', [ServiceController::class, 'suspend']);
$router->post('/admin/services/{id}/unsuspend', [ServiceController::class, 'unsuspend']);
$router->post('/admin/services/{id}/terminate', [ServiceController::class, 'terminate']);
$router->post('/admin/services/{id}/retry-provisioning', [ServiceController::class, 'retryProvisioning']);
