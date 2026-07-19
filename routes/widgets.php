<?php

declare(strict_types=1);

use CodeVault\Modules\WidgetController;

/** @var CodeVault\Router $router */

$router->get('/admin/widgets', [WidgetController::class, 'index']);
$router->post('/admin/widgets/{slug}/activate', [WidgetController::class, 'activate']);
$router->post('/admin/widgets/{slug}/deactivate', [WidgetController::class, 'deactivate']);
