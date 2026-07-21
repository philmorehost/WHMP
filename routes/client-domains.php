<?php

declare(strict_types=1);

use CodeVault\Domains\ClientDomainController;
use CodeVault\Domains\DomainRegistrationController;

/** @var CodeVault\Router $router */

$router->get('/domains/register', [DomainRegistrationController::class, 'searchForm']);
$router->post('/domains/register/add-to-cart', [DomainRegistrationController::class, 'addToCart']);
$router->get('/domains/availability', [DomainRegistrationController::class, 'checkAvailabilityAjax']);
$router->get('/domains/spin', [DomainRegistrationController::class, 'spin']);

$router->get('/domains/transfer', [DomainRegistrationController::class, 'transferForm']);
$router->post('/domains/transfer/add-to-cart', [DomainRegistrationController::class, 'addTransferToCart']);

$router->get('/client/domains', [ClientDomainController::class, 'index']);
$router->get('/client/domains/{id}', [ClientDomainController::class, 'show']);
$router->post('/client/domains/{id}/lock', [ClientDomainController::class, 'toggleLock']);
$router->post('/client/domains/{id}/id-protection', [ClientDomainController::class, 'toggleIdProtection']);
$router->post('/client/domains/{id}/nameservers', [ClientDomainController::class, 'saveNameservers']);
$router->post('/client/domains/{id}/epp-code', [ClientDomainController::class, 'eppCode']);
