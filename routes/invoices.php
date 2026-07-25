<?php

declare(strict_types=1);

use CodeVault\Billing\AdminInvoiceController;
use CodeVault\Billing\ClientInvoiceController;
use CodeVault\Billing\PaymentCallbackController;

/** @var CodeVault\Router $router */

$router->get('/client/invoices', [ClientInvoiceController::class, 'index']);
$router->post('/client/invoices/mass-pay', [ClientInvoiceController::class, 'massPay']);
$router->get('/client/invoices/{id}', [ClientInvoiceController::class, 'show']);
$router->get('/client/invoices/{id}/pdf', [ClientInvoiceController::class, 'downloadPdf']);
$router->post('/client/invoices/{id}/apply-credit', [ClientInvoiceController::class, 'applyCredit']);
$router->post('/client/invoices/{id}/cancel', [ClientInvoiceController::class, 'cancel']);

$router->get('/client/wallet/add-funds', [ClientInvoiceController::class, 'addFundsForm']);
$router->post('/client/wallet/add-funds', [ClientInvoiceController::class, 'addFundsSubmit']);

$router->post('/client/invoices/{id}/pay/{gateway}', [PaymentCallbackController::class, 'initiate']);
// Server-issued init for on-page popup checkout — returns the reference and
// amount the server decided, so the popup never chooses either itself.
$router->post('/client/invoices/{id}/pay/{gateway}/init', [PaymentCallbackController::class, 'initiateInline']);
$router->get('/pay/{gateway}/callback', [PaymentCallbackController::class, 'callback']);
$router->post('/pay/{gateway}/webhook', [PaymentCallbackController::class, 'webhook']);

$router->get('/admin/invoices', [AdminInvoiceController::class, 'index']);
$router->get('/admin/invoices/{id}', [AdminInvoiceController::class, 'show']);
$router->get('/admin/invoices/{id}/pdf', [AdminInvoiceController::class, 'downloadPdf']);
$router->post('/admin/invoices/{id}/mark-paid', [AdminInvoiceController::class, 'markPaid']);
$router->post('/admin/invoices/{id}/cancel', [AdminInvoiceController::class, 'cancel']);
$router->post('/admin/invoices/{id}/refund', [AdminInvoiceController::class, 'refund']);
