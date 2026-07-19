<?php

declare(strict_types=1);

use CodeVault\Gdpr\GdprController;

/** @var CodeVault\Router $router */

$router->get('/admin/gdpr', [GdprController::class, 'index']);
$router->post('/admin/gdpr/settings', [GdprController::class, 'saveSettings']);
$router->post('/admin/gdpr/{id}/process', [GdprController::class, 'process']);
$router->post('/admin/gdpr/{id}/reject', [GdprController::class, 'reject']);
