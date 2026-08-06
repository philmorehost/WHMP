<?php

declare(strict_types=1);

use CodeVault\Domains\DomainController;
use CodeVault\Domains\RegistrarController;
use CodeVault\Domains\DomainPricingController;

/** @var CodeVault\Router $router */

$router->get('/admin/domains', [DomainController::class, 'index']);
$router->get('/admin/domains/whois-search', [DomainController::class, 'index']);
$router->post('/admin/domains/bulk-sync', [DomainController::class, 'bulkSync']);
$router->post('/admin/domains/bulk-refresh-ns', [DomainController::class, 'bulkRefreshNameservers']);
$router->post('/admin/domains/bulk-delete', [DomainController::class, 'bulkDelete']);
$router->post('/admin/domains/bulk-status', [DomainController::class, 'bulkUpdateStatus']);
$router->post('/admin/domains/nameservers', [DomainController::class, 'updateDefaultNameservers']);
$router->post('/admin/domains/deletion-policy', [DomainController::class, 'updateDeletionPolicy']);
$router->get('/admin/domains/create', [DomainController::class, 'createForm']);
$router->post('/admin/domains', [DomainController::class, 'store']);
$router->get('/admin/domains/{id}', [DomainController::class, 'show']);
$router->post('/admin/domains/{id}/status', [DomainController::class, 'updateStatus']);
$router->post('/admin/domains/{id}/registrar', [DomainController::class, 'updateRegistrar']);
$router->post('/admin/domains/{id}/register', [DomainController::class, 'registerDomain']);
$router->post('/admin/domains/{id}/renew', [DomainController::class, 'renew']);
$router->post('/admin/domains/{id}/sync', [DomainController::class, 'sync']);
$router->get('/admin/domains/{id}/whois', [DomainController::class, 'whois']);
$router->post('/admin/domains/{id}/whois', [DomainController::class, 'whois']);
$router->post('/admin/domains/{id}/lock', [DomainController::class, 'toggleLock']);
$router->post('/admin/domains/{id}/id-protection', [DomainController::class, 'toggleIdProtection']);
$router->post('/admin/domains/{id}/nameservers', [DomainController::class, 'saveNameservers']);
$router->post('/admin/domains/{id}/nameservers/refresh', [DomainController::class, 'refreshNameservers']);
$router->post('/admin/domains/{id}/child-ns', [DomainController::class, 'addChildNameserver']);
$router->post('/admin/domains/{id}/child-ns/{child_id}/delete', [DomainController::class, 'deleteChildNameserver']);
$router->post('/admin/domains/{id}/dns', [DomainController::class, 'addDnsRecord']);
$router->post('/admin/domains/{id}/dns/{record_id}/delete', [DomainController::class, 'deleteDnsRecord']);
$router->get('/admin/domains/{id}/contact', [DomainController::class, 'contactShow']);
$router->post('/admin/domains/{id}/contact', [DomainController::class, 'saveContact']);

$router->get('/admin/domain-pricing', [DomainPricingController::class, 'index']);
$router->post('/admin/domain-pricing', [DomainPricingController::class, 'store']);
$router->post('/admin/domain-pricing/bulk', [DomainPricingController::class, 'bulkStore']);
$router->post('/admin/domain-pricing/bulk-update', [DomainPricingController::class, 'bulkUpdate']);
$router->post('/admin/domain-pricing/reorder', [DomainPricingController::class, 'reorder']);
$router->post('/admin/domain-pricing/fetch-whmcs', [DomainPricingController::class, 'whmcsExtensions']);
$router->post('/admin/domain-pricing/{id}/delete', [DomainPricingController::class, 'destroy']);

$router->get('/admin/registrars', [RegistrarController::class, 'index']);
$router->post('/admin/registrars/{slug}/toggle', [RegistrarController::class, 'toggle']);
$router->post('/admin/registrars/{slug}/config', [RegistrarController::class, 'updateConfig']);
