<?php

declare(strict_types=1);

use CodeVault\CustomFields\CustomFieldController;

/** @var CodeVault\Router $router */

$router->get('/admin/custom-fields', [CustomFieldController::class, 'index']);
$router->get('/admin/custom-fields/create', [CustomFieldController::class, 'createForm']);
$router->post('/admin/custom-fields', [CustomFieldController::class, 'store']);
$router->get('/admin/custom-fields/{id}/edit', [CustomFieldController::class, 'editForm']);
$router->post('/admin/custom-fields/{id}', [CustomFieldController::class, 'update']);
$router->post('/admin/custom-fields/{id}/delete', [CustomFieldController::class, 'destroy']);
