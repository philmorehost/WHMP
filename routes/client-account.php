<?php

declare(strict_types=1);

use CodeVault\Billing\PaymentMethodController;
use CodeVault\Clients\ClientAccountController;
use CodeVault\Mail\ClientEmailLogController;

/** @var CodeVault\Router $router */

$router->get('/client/emails', [ClientEmailLogController::class, 'index']);

$router->get('/client/payment-methods', [PaymentMethodController::class, 'index']);
$router->post('/client/payment-methods/{id}/default', [PaymentMethodController::class, 'setDefault']);
$router->post('/client/payment-methods/{id}/delete', [PaymentMethodController::class, 'delete']);

$router->get('/client/account', [ClientAccountController::class, 'profile']);
$router->post('/client/account', [ClientAccountController::class, 'updateProfile']);
$router->post('/client/account/verify-vat', [ClientAccountController::class, 'verifyVat']);
$router->post('/client/account/password', [ClientAccountController::class, 'updatePassword']);
$router->post('/client/account/security-pin', [ClientAccountController::class, 'updateSecurityPin']);
$router->get('/client/account/security', [ClientAccountController::class, 'security']);
$router->post('/client/account/security/enable', [ClientAccountController::class, 'enable']);
$router->post('/client/account/security/confirm', [ClientAccountController::class, 'confirm']);
$router->post('/client/account/security/disable', [ClientAccountController::class, 'disable']);
$router->post('/client/account/security-question', [ClientAccountController::class, 'setupSecurityQuestion']);
$router->post('/client/account/security-question/clear', [ClientAccountController::class, 'clearSecurityQuestion']);
$router->get('/client/account/privacy', [ClientAccountController::class, 'privacy']);
$router->post('/client/account/privacy/export', [ClientAccountController::class, 'requestExport']);
$router->post('/client/account/privacy/erasure', [ClientAccountController::class, 'requestErasure']);
$router->get('/client/account/privacy/export/{id}/download', [ClientAccountController::class, 'downloadExport']);
