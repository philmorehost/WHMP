<?php

declare(strict_types=1);

use CodeVault\Ai\AiAdminController;
use CodeVault\Ai\AskAiController;
use CodeVault\Ai\OnboardingCopilotController;

/** @var CodeVault\Router $router */

// Client-area onboarding copilot (AJAX).
$router->post('/client/copilot/ask', [OnboardingCopilotController::class, 'ask']);

$router->get('/admin/ask-ai', [AskAiController::class, 'index']);
$router->post('/admin/ask-ai', [AskAiController::class, 'ask']);

$router->get('/admin/ai', [AiAdminController::class, 'index']);
$router->post('/admin/ai', [AiAdminController::class, 'update']);
