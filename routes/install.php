<?php

declare(strict_types=1);

use CodeVault\Install\InstallController;

/** @var CodeVault\Router $router */

$router->get('/install', [InstallController::class, 'requirements']);
$router->post('/install', [InstallController::class, 'requirements']);

$router->get('/install/database', [InstallController::class, 'database']);
$router->post('/install/database', [InstallController::class, 'database']);

$router->get('/install/admin', [InstallController::class, 'admin']);
$router->post('/install/admin', [InstallController::class, 'admin']);

$router->get('/install/finish', [InstallController::class, 'finish']);
$router->post('/install/finish', [InstallController::class, 'finish']);
