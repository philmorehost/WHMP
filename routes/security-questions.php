<?php

declare(strict_types=1);

use CodeVault\Modules\SecurityQuestionController;

/** @var CodeVault\Router $router */

$router->get('/admin/security-questions', [SecurityQuestionController::class, 'index']);
$router->post('/admin/security-questions/{slug}/activate', [SecurityQuestionController::class, 'activate']);
$router->post('/admin/security-questions/{slug}/deactivate', [SecurityQuestionController::class, 'deactivate']);
