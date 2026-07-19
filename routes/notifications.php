<?php

declare(strict_types=1);

use CodeVault\Notifications\NotificationEndpointController;

/** @var CodeVault\Router $router */

$router->get('/admin/notification-endpoints', [NotificationEndpointController::class, 'index']);
$router->post('/admin/notification-endpoints', [NotificationEndpointController::class, 'store']);
$router->post('/admin/notification-endpoints/{id}/toggle-active', [NotificationEndpointController::class, 'toggleActive']);
$router->post('/admin/notification-endpoints/{id}/delete', [NotificationEndpointController::class, 'destroy']);
