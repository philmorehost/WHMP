<?php

declare(strict_types=1);

use CodeVault\Billing\AdminInvoiceController;
use CodeVault\Billing\ClientInvoiceController;
use CodeVault\Billing\PaymentCallbackController;

/** @var CodeVault\Router $router */

$router->get('/client/invoices', [ClientInvoiceController::class, 'index']);
$router->get('/client/invoices/{id}', [ClientInvoiceController::class, 'show']);
$router->get('/client/invoices/{id}/pdf', [ClientInvoiceController::class, 'downloadPdf']);
$router->post('/client/invoices/{id}/apply-credit', [ClientInvoiceController::class, 'applyCredit']);

$router->post('/client/invoices/{id}/pay/{gateway}', [PaymentCallbackController::class, 'initiate']);
$router->get('/pay/{gateway}/callback', [PaymentCallbackController::class, 'callback']);
$router->post('/pay/{gateway}/webhook', [PaymentCallbackController::class, 'webhook']);

$router->get('/admin/invoices', [AdminInvoiceController::class, 'index']);
$router->get('/admin/invoices/{id}', [AdminInvoiceController::class, 'show']);
$router->get('/admin/invoices/{id}/pdf', [AdminInvoiceController::class, 'downloadPdf']);
$router->post('/admin/invoices/{id}/mark-paid', [AdminInvoiceController::class, 'markPaid']);
$router->post('/admin/invoices/{id}/cancel', [AdminInvoiceController::class, 'cancel']);
