<?php

declare(strict_types=1);

use CodeVault\Mail\EmailTemplateController;

/** @var CodeVault\Router $router */

$router->get('/admin/email-templates', [EmailTemplateController::class, 'index']);
$router->get('/admin/email-templates/{id}/edit', [EmailTemplateController::class, 'editForm']);
$router->post('/admin/email-templates/{id}', [EmailTemplateController::class, 'update']);
$router->get('/admin/email-log', [EmailTemplateController::class, 'log']);
