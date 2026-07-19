<?php

declare(strict_types=1);

use CodeVault\Staff\RoleController;
use CodeVault\Staff\StaffController;

/** @var CodeVault\Router $router */

$router->get('/admin/staff', [StaffController::class, 'index']);
$router->get('/admin/staff/create', [StaffController::class, 'createForm']);
$router->post('/admin/staff', [StaffController::class, 'store']);
$router->get('/admin/staff/{id}/edit', [StaffController::class, 'editForm']);
$router->post('/admin/staff/{id}', [StaffController::class, 'update']);
$router->post('/admin/staff/{id}/delete', [StaffController::class, 'destroy']);

$router->get('/admin/roles', [RoleController::class, 'index']);
$router->get('/admin/roles/create', [RoleController::class, 'createForm']);
$router->post('/admin/roles', [RoleController::class, 'store']);
$router->get('/admin/roles/{id}/edit', [RoleController::class, 'editForm']);
$router->post('/admin/roles/{id}', [RoleController::class, 'update']);
$router->post('/admin/roles/{id}/delete', [RoleController::class, 'destroy']);
