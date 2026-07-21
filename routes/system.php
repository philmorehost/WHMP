<?php

declare(strict_types=1);

use CodeVault\System\CronInfoController;

/** @var CodeVault\Router $router */

$router->get('/admin/cron', [CronInfoController::class, 'index']);
