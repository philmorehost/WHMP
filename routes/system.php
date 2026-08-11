<?php

declare(strict_types=1);

use CodeVault\Configuration\GeneralSettingsController;
use CodeVault\System\CronInfoController;
use CodeVault\System\HealthController;

/** @var CodeVault\Router $router */

// Liveness/readiness probe for uptime monitors and load balancers — always
// reachable, even during maintenance mode (see Kernel::handle()).
$router->get('/health', [HealthController::class, 'index']);

$router->get('/admin/cron', [CronInfoController::class, 'index']);
$router->post('/admin/cron/automation', [CronInfoController::class, 'update']);
$router->get('/admin/settings/general', [GeneralSettingsController::class, 'index']);
$router->post('/admin/settings/general', [GeneralSettingsController::class, 'update']);
$router->post('/admin/settings/general/test-send', [GeneralSettingsController::class, 'sendTest']);
