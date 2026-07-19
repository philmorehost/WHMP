<?php

declare(strict_types=1);

use CodeVault\Domains\ClientDomainController;

/** @var CodeVault\Router $router */

$router->get('/client/domains', [ClientDomainController::class, 'index']);
$router->get('/client/domains/{id}', [ClientDomainController::class, 'show']);
$router->post('/client/domains/{id}/lock', [ClientDomainController::class, 'toggleLock']);
$router->post('/client/domains/{id}/id-protection', [ClientDomainController::class, 'toggleIdProtection']);
$router->post('/client/domains/{id}/nameservers', [ClientDomainController::class, 'saveNameservers']);
$router->post('/client/domains/{id}/epp-code', [ClientDomainController::class, 'eppCode']);
