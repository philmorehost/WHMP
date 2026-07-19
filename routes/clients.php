<?php

declare(strict_types=1);

use CodeVault\Clients\ClientController;
use CodeVault\Clients\ClientGroupController;

/** @var CodeVault\Router $router */

$router->get('/admin/client-groups', [ClientGroupController::class, 'index']);
$router->post('/admin/client-groups', [ClientGroupController::class, 'store']);
$router->post('/admin/client-groups/{id}/delete', [ClientGroupController::class, 'destroy']);

$router->get('/admin/clients', [ClientController::class, 'index']);
$router->get('/admin/clients/export', [ClientController::class, 'export']);
$router->get('/admin/clients/create', [ClientController::class, 'createForm']);
$router->post('/admin/clients', [ClientController::class, 'store']);
$router->get('/admin/clients/{id}', [ClientController::class, 'show']);
$router->get('/admin/clients/{id}/edit', [ClientController::class, 'editForm']);
$router->post('/admin/clients/{id}', [ClientController::class, 'update']);
$router->post('/admin/clients/{id}/verify-vat', [ClientController::class, 'verifyVat']);
$router->post('/admin/clients/{id}/close', [ClientController::class, 'close']);
$router->post('/admin/clients/{id}/contacts', [ClientController::class, 'addContact']);
$router->post('/admin/clients/{id}/contacts/{contactId}/delete', [ClientController::class, 'removeContact']);
$router->post('/admin/clients/{id}/credit', [ClientController::class, 'grantCredit']);
$router->post('/admin/clients/{id}/login-as', [ClientController::class, 'loginAsClient']);
