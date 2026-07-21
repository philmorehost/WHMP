<?php

declare(strict_types=1);

use CodeVault\Import\EntityImportController;
use CodeVault\Import\ImportController;
use CodeVault\Import\WhmcsImportController;

/** @var CodeVault\Router $router */

$router->get('/admin/import/clients', [ImportController::class, 'form']);
$router->post('/admin/import/clients', [ImportController::class, 'run']);
$router->get('/admin/import/whmcs', [WhmcsImportController::class, 'form']);
$router->get('/admin/import/whmcs/progress', [WhmcsImportController::class, 'progress']);
$router->post('/admin/import/whmcs', [WhmcsImportController::class, 'run']);
$router->get('/admin/import/{type}', [EntityImportController::class, 'form']);
$router->post('/admin/import/{type}', [EntityImportController::class, 'run']);
