<?php

declare(strict_types=1);

use CodeVault\Cloudflare\CloudflareAdminController;
use CodeVault\Cloudflare\CloudflareClientController;

/** @var CodeVault\Router $router */

// Cloudflare CDN & Security (Free) — docs/CLOUDFLARE_ADDON_PLAN.md. Every
// controller answers 404 (client) or redirects to the add-on page (admin) while
// the add-on is inactive.

// Client — main site and every reseller store, owner of the service only.
$router->get('/client/services/{id}/cloudflare', [CloudflareClientController::class, 'show']);
$router->post('/client/services/{id}/cloudflare/enable', [CloudflareClientController::class, 'enable']);
$router->post('/client/services/{id}/cloudflare/check', [CloudflareClientController::class, 'check']);
$router->post('/client/services/{id}/cloudflare/nameservers', [CloudflareClientController::class, 'nameservers']);
$router->post('/client/services/{id}/cloudflare/disable', [CloudflareClientController::class, 'disable']);
$router->post('/client/services/{id}/cloudflare/keep', [CloudflareClientController::class, 'keep']);
$router->post('/client/services/{id}/cloudflare/dns', [CloudflareClientController::class, 'saveDns']);
$router->post('/client/services/{id}/cloudflare/dns/{rid}', [CloudflareClientController::class, 'saveDns']);
$router->post('/client/services/{id}/cloudflare/dns/{rid}/delete', [CloudflareClientController::class, 'deleteDns']);
$router->post('/client/services/{id}/cloudflare/setting', [CloudflareClientController::class, 'setting']);
$router->post('/client/services/{id}/cloudflare/purge', [CloudflareClientController::class, 'purge']);
$router->post('/client/services/{id}/cloudflare/rules', [CloudflareClientController::class, 'addRule']);
$router->post('/client/services/{id}/cloudflare/rules/{rule}/delete', [CloudflareClientController::class, 'deleteRule']);
$router->post('/client/services/{id}/cloudflare/rulesets/{kind}', [CloudflareClientController::class, 'addRuleset']);
$router->post('/client/services/{id}/cloudflare/rulesets/{kind}/{rule}/delete', [CloudflareClientController::class, 'deleteRuleset']);
$router->post('/client/services/{id}/cloudflare/presets/{preset}', [CloudflareClientController::class, 'preset']);
$router->post('/client/services/{id}/cloudflare/dnssec/{action}', [CloudflareClientController::class, 'dnssec']);
$router->post('/client/services/{id}/cloudflare/origin-certificate', [CloudflareClientController::class, 'originCertificate']);
$router->post('/client/services/{id}/cloudflare/email-routing/enable', [CloudflareClientController::class, 'enableEmailRouting']);
$router->post('/client/services/{id}/cloudflare/email-routing/disable', [CloudflareClientController::class, 'disableEmailRouting']);
$router->post('/client/services/{id}/cloudflare/email-routing/destinations', [CloudflareClientController::class, 'addEmailDestination']);
$router->post('/client/services/{id}/cloudflare/email-routing/routes', [CloudflareClientController::class, 'addEmailRoute']);
$router->post('/client/services/{id}/cloudflare/email-routing/routes/{rule}/toggle', [CloudflareClientController::class, 'toggleEmailRoute']);
$router->post('/client/services/{id}/cloudflare/email-routing/routes/{rule}/delete', [CloudflareClientController::class, 'deleteEmailRoute']);
$router->post('/client/services/{id}/cloudflare/email-routing/catch-all', [CloudflareClientController::class, 'setEmailCatchAll']);

// Admin (addons.manage).
$router->get('/admin/cloudflare', [CloudflareAdminController::class, 'index']);
$router->get('/admin/cloudflare/settings', [CloudflareAdminController::class, 'settingsPage']);
$router->get('/admin/cloudflare/import', [CloudflareAdminController::class, 'importPage']);
$router->post('/admin/cloudflare/import', [CloudflareAdminController::class, 'importZone']);
$router->post('/admin/cloudflare/settings', [CloudflareAdminController::class, 'saveSettings']);
$router->post('/admin/cloudflare/connect', [CloudflareAdminController::class, 'connect']);
$router->post('/admin/cloudflare/disconnect', [CloudflareAdminController::class, 'disconnect']);
$router->post('/admin/cloudflare/products', [CloudflareAdminController::class, 'saveProducts']);
$router->get('/admin/cloudflare/zones/{id}', [CloudflareAdminController::class, 'zone']);
$router->get('/admin/cloudflare/zones/{id}/backup', [CloudflareAdminController::class, 'backup']);
$router->post('/admin/cloudflare/zones/{id}/{action}', [CloudflareAdminController::class, 'zoneAction']);
