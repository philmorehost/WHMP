<?php

declare(strict_types=1);

use CodeVault\Billing\ClientServiceController;

/** @var CodeVault\Router $router */

$router->get('/client/services', [ClientServiceController::class, 'index']);
$router->get('/client/services/{id}', [ClientServiceController::class, 'show']);
$router->get('/client/services/{id}/upgrade', [ClientServiceController::class, 'upgradeForm']);
$router->post('/client/services/{id}/upgrade', [ClientServiceController::class, 'upgrade']);
$router->get('/client/services/{id}/addons', [ClientServiceController::class, 'addons']);
$router->post('/client/services/{id}/addons', [ClientServiceController::class, 'orderAddon']);
$router->post('/client/services/{id}/addon-remove', [ClientServiceController::class, 'removeAddon']);
$router->post('/client/services/{id}/sso', [ClientServiceController::class, 'sso']);
$router->get('/client/services/{id}/cancel', [ClientServiceController::class, 'cancelForm']);
$router->post('/client/services/{id}/cancel', [ClientServiceController::class, 'cancel']);
$router->post('/client/services/{id}/power', [ClientServiceController::class, 'power']);
$router->post('/client/services/{id}/vnc', [ClientServiceController::class, 'vnc']);
$router->post('/client/services/{id}/backup', [ClientServiceController::class, 'backup']);
$router->post('/client/services/{id}/restore', [ClientServiceController::class, 'restore']);
$router->post('/client/services/{id}/reinstall', [ClientServiceController::class, 'reinstall']);
$router->post('/client/services/{id}/rdns', [ClientServiceController::class, 'rdns']);
$router->post('/client/services/{id}/change-domain', [ClientServiceController::class, 'changeDomain']);
