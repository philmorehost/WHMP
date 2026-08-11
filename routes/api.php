<?php

declare(strict_types=1);

use CodeVault\Api\ApiCredentialController;
use CodeVault\Api\ApiResourceController;
use CodeVault\Api\ApiResponse;
use CodeVault\Container;
use CodeVault\Request;
use CodeVault\Response;

/** @var CodeVault\Router $router */

// Public liveness probe — no auth, no session, so an external monitor or
// integration can confirm the API is up without holding credentials.
$router->get('/api/ping', function (Request $request, array $params, Container $container): Response {
    return ApiResponse::success([
        'pong' => true,
        'time' => date(DATE_ATOM),
    ]);
});

// Admin credential management (protected by the settings.manage permission
// in the controller — this is the staff UI, not the external API).
$router->get('/admin/api-credentials', [ApiCredentialController::class, 'index']);
$router->post('/admin/api-credentials', [ApiCredentialController::class, 'store']);
$router->post('/admin/api-credentials/{id}/active', [ApiCredentialController::class, 'setActive']);
$router->post('/admin/api-credentials/{id}/delete', [ApiCredentialController::class, 'destroy']);

// External REST API — every route authenticates via Bearer key.secret and
// checks a scope (ApiResourceController). Scopes gate each resource:
// clients.read, invoices.read/write, services.read, domains.read,
// tickets.read/write.
$router->get('/api/clients', [ApiResourceController::class, 'clients']);
$router->get('/api/clients/{id}', [ApiResourceController::class, 'client']);
$router->get('/api/invoices', [ApiResourceController::class, 'invoices']);
$router->get('/api/invoices/{id}', [ApiResourceController::class, 'invoice']);
$router->post('/api/invoices', [ApiResourceController::class, 'createInvoice']);
$router->get('/api/services', [ApiResourceController::class, 'services']);
$router->get('/api/domains', [ApiResourceController::class, 'domains']);
$router->get('/api/tickets', [ApiResourceController::class, 'tickets']);
$router->post('/api/tickets/{id}/reply', [ApiResourceController::class, 'replyToTicket']);
