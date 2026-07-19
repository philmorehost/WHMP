<?php

declare(strict_types=1);

use CodeVault\Domains\DomainController;
use CodeVault\Domains\RegistrarController;
use CodeVault\Domains\DomainPricingController;

/** @var CodeVault\Router $router */

$router->get('/admin/domains', [DomainController::class, 'index']);
$router->get('/admin/domains/create', [DomainController::class, 'createForm']);
$router->post('/admin/domains', [DomainController::class, 'store']);
$router->get('/admin/domains/{id}', [DomainController::class, 'show']);
$router->post('/admin/domains/{id}/renew', [DomainController::class, 'renew']);
$router->post('/admin/domains/{id}/sync', [DomainController::class, 'sync']);
$router->post('/admin/domains/{id}/lock', [DomainController::class, 'toggleLock']);
$router->post('/admin/domains/{id}/id-protection', [DomainController::class, 'toggleIdProtection']);
$router->post('/admin/domains/{id}/nameservers', [DomainController::class, 'saveNameservers']);
$router->post('/admin/domains/{id}/nameservers/refresh', [DomainController::class, 'refreshNameservers']);
$router->get('/admin/domains/{id}/contact', [DomainController::class, 'contactShow']);
$router->post('/admin/domains/{id}/contact', [DomainController::class, 'saveContact']);

$router->get('/admin/domain-pricing', [DomainPricingController::class, 'index']);
$router->post('/admin/domain-pricing', [DomainPricingController::class, 'store']);
$router->post('/admin/domain-pricing/{id}/delete', [DomainPricingController::class, 'destroy']);

$router->get('/admin/registrars', [RegistrarController::class, 'index']);
$router->post('/admin/registrars/{slug}/toggle', [RegistrarController::class, 'toggle']);
$router->post('/admin/registrars/{slug}/config', [RegistrarController::class, 'updateConfig']);
