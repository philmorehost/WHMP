<?php

declare(strict_types=1);

use CodeVault\Billing\ServiceController;
use CodeVault\Billing\CancellationRequestsController;

/** @var CodeVault\Router $router */

$router->get('/admin/services', [ServiceController::class, 'index']);
$router->get('/admin/services/{id}', [ServiceController::class, 'show']);
$router->post('/admin/services/{id}/upgrade', [ServiceController::class, 'upgrade']);
$router->post('/admin/services/{id}/suspend', [ServiceController::class, 'suspend']);
$router->post('/admin/services/{id}/unsuspend', [ServiceController::class, 'unsuspend']);
$router->post('/admin/services/{id}/terminate', [ServiceController::class, 'terminate']);
$router->post('/admin/services/{id}/retry-provisioning', [ServiceController::class, 'retryProvisioning']);
$router->post('/admin/services/{id}/edit', [ServiceController::class, 'updateDetails']);

$router->get('/admin/cancellations', [CancellationRequestsController::class, 'adminIndex']);
$router->post('/admin/cancellations/{id}/approve', [CancellationRequestsController::class, 'adminApprove']);
$router->post('/admin/cancellations/{id}/reject', [CancellationRequestsController::class, 'adminReject']);

$router->post('/client/services/{id}/cancel-request', [CancellationRequestsController::class, 'clientCreate']);
