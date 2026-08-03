<?php

declare(strict_types=1);

use CodeVault\Notifications\NotificationEndpointController;
use CodeVault\Notifications\ClientNotificationController;
use CodeVault\Notifications\ClientNotificationCenterController;

/** @var CodeVault\Router $router */

$router->get('/admin/notification-endpoints', [NotificationEndpointController::class, 'index']);
$router->post('/admin/notification-endpoints', [NotificationEndpointController::class, 'store']);
$router->post('/admin/notification-endpoints/{id}/toggle-active', [NotificationEndpointController::class, 'toggleActive']);
$router->post('/admin/notification-endpoints/{id}/delete', [NotificationEndpointController::class, 'destroy']);

$router->get('/admin/client-notifications', [ClientNotificationController::class, 'index']);
$router->post('/admin/client-notifications', [ClientNotificationController::class, 'store']);
$router->get('/admin/client-notifications/{id}', [ClientNotificationController::class, 'show']);

$router->get('/client/notifications', [ClientNotificationCenterController::class, 'index']);
$router->get('/client/notifications/{id}', [ClientNotificationCenterController::class, 'show']);
$router->post('/client/notifications/{id}/reply', [ClientNotificationCenterController::class, 'reply']);
