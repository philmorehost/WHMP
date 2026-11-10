<?php

declare(strict_types=1);

use CodeVault\Billing\AdminInvoiceController;
use CodeVault\Billing\ClientInvoiceController;
use CodeVault\Billing\PaymentCallbackController;
use CodeVault\Billing\RecurringInvoiceController;

/** @var CodeVault\Router $router */

$router->get('/client/invoices', [ClientInvoiceController::class, 'index']);
$router->post('/client/invoices/mass-pay', [ClientInvoiceController::class, 'massPay']);
$router->get('/client/invoices/{id}', [ClientInvoiceController::class, 'show']);
$router->get('/client/invoices/{id}/pdf', [ClientInvoiceController::class, 'downloadPdf']);
$router->post('/client/invoices/{id}/apply-credit', [ClientInvoiceController::class, 'applyCredit']);
$router->post('/client/invoices/{id}/cancel', [ClientInvoiceController::class, 'cancel']);

// Pending ad-hoc charges the client can withdraw before they are invoiced.
$router->post('/client/billable-items/{id}/cancel', [ClientInvoiceController::class, 'cancelBillable']);

$router->get('/client/wallet/add-funds', [ClientInvoiceController::class, 'addFundsForm']);
$router->post('/client/wallet/add-funds', [ClientInvoiceController::class, 'addFundsSubmit']);

// Manual crypto payments (USDT TRC-20): instructions + proof upload. The
// pay button POSTs to /pay/crypto_manual which redirects here instead of
// capturing — these pages still re-verify auth, ownership, gateway state
// and the audience toggles themselves.
$router->get('/client/invoices/{id}/crypto', [\CodeVault\Billing\ClientCryptoManualController::class, 'instructions']);
$router->post('/client/invoices/{id}/crypto', [\CodeVault\Billing\ClientCryptoManualController::class, 'submit']);

$router->post('/client/invoices/{id}/pay/{gateway}', [PaymentCallbackController::class, 'initiate']);
// Server-issued init for on-page popup checkout — returns the reference and
// amount the server decided, so the popup never chooses either itself.
$router->post('/client/invoices/{id}/pay/{gateway}/init', [PaymentCallbackController::class, 'initiateInline']);
$router->get('/pay/{gateway}/callback', [PaymentCallbackController::class, 'callback']);
$router->post('/pay/{gateway}/webhook', [PaymentCallbackController::class, 'webhook']);

$router->get('/admin/invoices', [AdminInvoiceController::class, 'index']);

// Manual crypto payment review queue (USDT TRC-20 proofs).
$router->get('/admin/crypto-payments', [\CodeVault\Billing\AdminCryptoManualController::class, 'index']);
$router->get('/admin/crypto-payments/{id}/proof', [\CodeVault\Billing\AdminCryptoManualController::class, 'proof']);
$router->post('/admin/crypto-payments/{id}/approve', [\CodeVault\Billing\AdminCryptoManualController::class, 'approve']);
$router->post('/admin/crypto-payments/{id}/reject', [\CodeVault\Billing\AdminCryptoManualController::class, 'reject']);
// Literal paths must precede /admin/invoices/{id}: the router returns the
// first pattern that matches, and {id} would otherwise swallow "create".
$router->get('/admin/invoices/create', [AdminInvoiceController::class, 'createForm']);
$router->post('/admin/invoices/create', [AdminInvoiceController::class, 'store']);
$router->post('/admin/invoices/bulk-cancel', [AdminInvoiceController::class, 'bulkCancel']);
$router->get('/admin/recurring-invoices', [RecurringInvoiceController::class, 'index']);
$router->post('/admin/recurring-invoices/{id}/status', [RecurringInvoiceController::class, 'setStatus']);
$router->post('/admin/invoices/mark-zero-paid', [AdminInvoiceController::class, 'markZeroValuePaid']);
$router->post('/admin/invoices/cancel-dead-services', [AdminInvoiceController::class, 'cancelForDeadServices']);
$router->post('/admin/invoices/bulk-remind', [AdminInvoiceController::class, 'bulkSendReminders']);
$router->post('/admin/invoices/remind-all-unpaid', [AdminInvoiceController::class, 'remindAllUnpaid']);
$router->get('/admin/invoices/{id}', [AdminInvoiceController::class, 'show']);
$router->get('/admin/invoices/{id}/pdf', [AdminInvoiceController::class, 'downloadPdf']);
$router->post('/admin/invoices/{id}/mark-paid', [AdminInvoiceController::class, 'markPaid']);
$router->post('/admin/invoices/{id}/cancel', [AdminInvoiceController::class, 'cancel']);
$router->post('/admin/invoices/{id}/status', [AdminInvoiceController::class, 'setStatus']);
$router->post('/admin/invoices/{id}/refund', [AdminInvoiceController::class, 'refund']);
$router->post('/admin/invoices/{id}/send-reminder', [AdminInvoiceController::class, 'sendReminder']);
$router->post('/admin/invoices/{id}/items', [AdminInvoiceController::class, 'updateItems']);
