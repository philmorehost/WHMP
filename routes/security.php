<?php

declare(strict_types=1);

use CodeVault\Security\SecurityController;

/** @var CodeVault\Router $router */

$router->get('/admin/security', [SecurityController::class, 'index']);
$router->post('/admin/security/settings', [SecurityController::class, 'updateAuthSettings']);
$router->post('/admin/security/ip', [SecurityController::class, 'addIpRule']);
$router->post('/admin/security/ip/remove', [SecurityController::class, 'removeIpRule']);
$router->post('/admin/security/country', [SecurityController::class, 'setCountryRule']);
$router->post('/admin/security/unlock', [SecurityController::class, 'unlockAccount']);
