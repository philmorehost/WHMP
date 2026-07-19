<?php

declare(strict_types=1);

use CodeVault\Billing\ClientServiceController;

/** @var CodeVault\Router $router */

$router->get('/client/services', [ClientServiceController::class, 'index']);
$router->get('/client/services/{id}', [ClientServiceController::class, 'show']);
$router->post('/client/services/{id}/sso', [ClientServiceController::class, 'sso']);
$router->post('/client/services/{id}/cancel', [ClientServiceController::class, 'cancel']);
