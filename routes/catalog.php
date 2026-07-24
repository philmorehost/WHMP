<?php

declare(strict_types=1);

use CodeVault\Catalog\ConfigurableOptionController;
use CodeVault\Catalog\ProductController;
use CodeVault\Catalog\ProductGroupController;

/** @var CodeVault\Router $router */

$router->get('/admin/products/groups', [ProductGroupController::class, 'index']);
$router->post('/admin/products/groups', [ProductGroupController::class, 'store']);
$router->post('/admin/products/groups/{id}', [ProductGroupController::class, 'update']);
$router->post('/admin/products/groups/{id}/edit', [ProductGroupController::class, 'update']);
$router->post('/admin/products/groups/{id}/delete', [ProductGroupController::class, 'destroy']);

$router->get('/admin/products', [ProductController::class, 'index']);
$router->get('/admin/products/create', [ProductController::class, 'createForm']);
$router->post('/admin/products', [ProductController::class, 'store']);
$router->post('/admin/products/bulk-update', [ProductController::class, 'bulkUpdate']);
$router->get('/admin/products/{id}/edit', [ProductController::class, 'editForm']);
$router->post('/admin/products/{id}', [ProductController::class, 'update']);
$router->post('/admin/products/{id}/delete', [ProductController::class, 'destroy']);

$router->get('/admin/configurable-options', [ConfigurableOptionController::class, 'index']);
$router->post('/admin/configurable-options', [ConfigurableOptionController::class, 'storeGroup']);
$router->post('/admin/configurable-options/{id}/edit', [ConfigurableOptionController::class, 'updateGroup']);
$router->post('/admin/configurable-options/{id}/delete', [ConfigurableOptionController::class, 'destroyGroup']);
$router->post('/admin/configurable-options/bulk-delete', [ConfigurableOptionController::class, 'bulkDeleteGroups']);
$router->get('/admin/configurable-options/{id}', [ConfigurableOptionController::class, 'show']);
$router->post('/admin/configurable-options/{id}/options', [ConfigurableOptionController::class, 'storeOption']);
$router->post('/admin/configurable-options/{id}/options/{optionId}/delete', [ConfigurableOptionController::class, 'destroyOption']);
$router->post('/admin/configurable-options/{id}/options/{optionId}/update', [ConfigurableOptionController::class, 'updateOption']);
$router->post('/admin/configurable-options/{id}/options/bulk-delete', [ConfigurableOptionController::class, 'bulkDeleteOptions']);
