<?php

declare(strict_types=1);

use CodeVault\Billing\ServiceController;
use CodeVault\Billing\CancellationRequestsController;
use CodeVault\Billing\ClientCancellationController;

/** @var CodeVault\Router $router */

$router->get('/admin/services', [ServiceController::class, 'index']);
$router->post('/admin/services/bulk-delete', [ServiceController::class, 'bulkDelete']);
$router->get('/admin/services/{id}', [ServiceController::class, 'show']);
$router->post('/admin/services/{id}/delete', [ServiceController::class, 'delete']);
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
$router->post('/client/services/{id}/power', [\CodeVault\Billing\ClientServiceController::class, 'power']);
$router->post('/client/services/{id}/vnc', [\CodeVault\Billing\ClientServiceController::class, 'vnc']);
$router->post('/client/services/{id}/backup', [\CodeVault\Billing\ClientServiceController::class, 'backup']);
$router->post('/client/services/{id}/restore', [\CodeVault\Billing\ClientServiceController::class, 'restore']);
$router->post('/client/services/{id}/reinstall', [\CodeVault\Billing\ClientServiceController::class, 'reinstall']);
$router->post('/client/services/{id}/rdns', [\CodeVault\Billing\ClientServiceController::class, 'rdns']);

$router->post('/client/orders/{id}/cancel', [ClientCancellationController::class, 'cancelOrder']);
$router->post('/client/invoices/{id}/cancel', [ClientCancellationController::class, 'cancelInvoice']);
