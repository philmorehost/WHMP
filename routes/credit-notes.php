<?php

declare(strict_types=1);

use CodeVault\Billing\ClientCreditNoteController;
use CodeVault\Billing\CreditNoteController;

/** @var CodeVault\Router $router */

$router->get('/admin/credit-notes', [CreditNoteController::class, 'index']);
$router->get('/admin/credit-notes/create', [CreditNoteController::class, 'createForm']);
$router->post('/admin/credit-notes', [CreditNoteController::class, 'store']);
$router->get('/admin/credit-notes/{id}', [CreditNoteController::class, 'show']);
$router->get('/admin/credit-notes/{id}/pdf', [CreditNoteController::class, 'downloadPdf']);

$router->get('/client/credit-notes', [ClientCreditNoteController::class, 'index']);
$router->get('/client/credit-notes/{id}/pdf', [ClientCreditNoteController::class, 'downloadPdf']);
