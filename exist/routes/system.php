<?php

declare(strict_types=1);

use CodeVault\Configuration\GeneralSettingsController;
use CodeVault\System\AutomationSettingsController;
use CodeVault\System\CronInfoController;

/** @var CodeVault\Router $router */

$router->get('/admin/cron', [CronInfoController::class, 'index']);
$router->get('/admin/settings/general', [GeneralSettingsController::class, 'index']);
$router->post('/admin/settings/general', [GeneralSettingsController::class, 'update']);
$router->get('/admin/system/automation', [AutomationSettingsController::class, 'index']);
$router->post('/admin/system/automation', [AutomationSettingsController::class, 'update']);
