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
$router->post('/admin/services/{id}/create-account', [ServiceController::class, 'createAccount']);
$router->post('/admin/services/{id}/retry-provisioning', [ServiceController::class, 'retryProvisioning']);
$router->post('/admin/services/{id}/edit', [ServiceController::class, 'updateDetails']);
$router->post('/admin/services/{id}/send-details', [ServiceController::class, 'sendDetails']);
$router->post('/admin/services/{id}/status', [ServiceController::class, 'setStatus']);

$router->get('/admin/cancellations', [CancellationRequestsController::class, 'adminIndex']);
$router->post('/admin/cancellations/{id}/approve', [CancellationRequestsController::class, 'adminApprove']);
$router->post('/admin/cancellations/{id}/complete', [CancellationRequestsController::class, 'adminComplete']);
$router->post('/admin/cancellations/{id}/reject', [CancellationRequestsController::class, 'adminReject']);

$router->post('/client/services/{id}/cancel-request', [CancellationRequestsController::class, 'clientCreate']);

// The per-service management actions live in routes/client-services.php
// alongside the rest of the client service routes. `reinstall` and `rdns`
// used to be declared in both files.

$router->post('/client/orders/{id}/cancel', [ClientCancellationController::class, 'cancelOrder']);

// NOTE: /client/invoices/{id}/cancel is deliberately NOT registered here.
//
// It was declared both here and in routes/invoices.php. The router returns the
// first match and invoices.php loads first, so this one never ran — the
// behaviour depended entirely on the order of the file list in public/index.php,
// which is a trap waiting for whoever reorders it.
//
// ClientInvoiceController::cancel is the one that runs, and it is the correct
// one: it sets status = 'cancelled', which is what dunning, auto-charge and
// every listing read. ClientCancellationController::cancelInvoice only sets
// is_cancelled/cancelled_at/cancellation_reason and leaves status alone, so the
// invoice would have stayed 'unpaid' and kept being chased for payment despite
// telling the client cancellation "will prevent automated billing attempts".
