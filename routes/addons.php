<?php

declare(strict_types=1);

use CodeVault\Modules\AddonController;

/** @var CodeVault\Router $router */

$router->get('/admin/addons', [AddonController::class, 'index']);
$router->get('/admin/addons/{slug}', [AddonController::class, 'show']);
$router->post('/admin/addons/{slug}/activate', [AddonController::class, 'activate']);
$router->post('/admin/addons/{slug}/deactivate', [AddonController::class, 'deactivate']);
