<?php

declare(strict_types=1);

use CodeVault\UsernameChanger\AdminUsernameController;
use CodeVault\UsernameChanger\ClientUsernameController;
use CodeVault\UsernameChanger\ResellerUsernameController;

/** @var CodeVault\Router $router */

// cPanel Username Changer (docs/CPANEL_USERNAME_CHANGER_PLAN.md §7). Every
// controller answers 404 while the add-on is inactive.

// Client — main site and every store, owner of the service only.
$router->get('/client/services/{id}/username', [ClientUsernameController::class, 'show']);
$router->get('/client/services/{id}/username/check', [ClientUsernameController::class, 'check']);
$router->post('/client/services/{id}/username', [ClientUsernameController::class, 'submit']);
$router->post('/client/services/{id}/username/{rid}/cancel', [ClientUsernameController::class, 'cancel']);
$router->post('/client/services/{id}/username/{rid}/resend', [ClientUsernameController::class, 'resend']);

// Emailed confirmation — works signed out, on the site it was issued for.
$router->get('/username-change/confirm/{token}', [ClientUsernameController::class, 'confirmPage']);
$router->post('/username-change/confirm/{token}', [ClientUsernameController::class, 'confirm']);

// Reseller panel — the store's own customers only.
$router->get('/client/reseller/username-requests', [ResellerUsernameController::class, 'index']);
$router->post('/client/reseller/username-requests/policy', [ResellerUsernameController::class, 'savePolicy']);
$router->post('/client/reseller/username-requests/{id}/{action}', [ResellerUsernameController::class, 'decide']);

// Super admin.
$router->get('/admin/username-changer', [AdminUsernameController::class, 'index']);
$router->get('/admin/username-changer/check', [AdminUsernameController::class, 'check']);
$router->get('/admin/username-changer/manual', [AdminUsernameController::class, 'manual']);
$router->post('/admin/username-changer/manual', [AdminUsernameController::class, 'manual']);
$router->get('/admin/username-changer/settings', [AdminUsernameController::class, 'settingsPage']);
$router->post('/admin/username-changer/settings', [AdminUsernameController::class, 'saveSettings']);
$router->post('/admin/username-changer/policies/products', [AdminUsernameController::class, 'saveProductPolicies']);
$router->post('/admin/username-changer/policies/client', [AdminUsernameController::class, 'saveClientPolicy']);
$router->post('/admin/username-changer/servers/{id}/{action}', [AdminUsernameController::class, 'serverAction']);
$router->get('/admin/username-changer/audit', [AdminUsernameController::class, 'audit']);
$router->get('/admin/username-changer/requests/{id}', [AdminUsernameController::class, 'show']);
$router->post('/admin/username-changer/requests/{id}/{action}', [AdminUsernameController::class, 'action']);
