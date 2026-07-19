<?php

declare(strict_types=1);

use CodeVault\Ai\AskAiController;

/** @var CodeVault\Router $router */

$router->get('/admin/ask-ai', [AskAiController::class, 'index']);
$router->post('/admin/ask-ai', [AskAiController::class, 'ask']);
