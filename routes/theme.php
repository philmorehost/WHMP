<?php

declare(strict_types=1);

use CodeVault\Theme\ThemeController;

/** @var CodeVault\Router $router */

$router->get('/admin/theme', [ThemeController::class, 'index']);
$router->post('/admin/theme', [ThemeController::class, 'update']);
