<?php

declare(strict_types=1);

use CodeVault\Auth\AdminAccountController;

/** @var CodeVault\Router $router */

$router->get('/admin/account/security', [AdminAccountController::class, 'security']);
$router->post('/admin/account/security/enable', [AdminAccountController::class, 'enable']);
$router->post('/admin/account/security/confirm', [AdminAccountController::class, 'confirm']);
$router->post('/admin/account/security/disable', [AdminAccountController::class, 'disable']);
