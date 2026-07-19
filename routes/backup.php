<?php

declare(strict_types=1);

use CodeVault\Backup\BackupController;

/** @var CodeVault\Router $router */

$router->get('/admin/backups', [BackupController::class, 'index']);
$router->post('/admin/backups', [BackupController::class, 'trigger']);
