<?php

declare(strict_types=1);

use CodeVault\Knowledgebase\KbArticleController;
use CodeVault\Knowledgebase\KbCategoryController;
use CodeVault\Knowledgebase\KbCopilotController;
use CodeVault\Knowledgebase\KbImageController;
use CodeVault\Knowledgebase\PublicKbController;

/** @var CodeVault\Router $router */

$router->get('/admin/kb/categories', [KbCategoryController::class, 'index']);
$router->post('/admin/kb/categories', [KbCategoryController::class, 'store']);
$router->post('/admin/kb/categories/copilot', [KbCopilotController::class, 'generateCategory']);
$router->post('/admin/kb/categories/{id}/update', [KbCategoryController::class, 'update']);
$router->post('/admin/kb/categories/{id}/delete', [KbCategoryController::class, 'destroy']);

$router->get('/admin/kb/articles', [KbArticleController::class, 'index']);
$router->get('/admin/kb/articles/create', [KbArticleController::class, 'createForm']);
$router->post('/admin/kb/articles', [KbArticleController::class, 'store']);
$router->post('/admin/kb/articles/copilot', [KbCopilotController::class, 'generateArticle']);
$router->get('/admin/kb/articles/{id}/edit', [KbArticleController::class, 'editForm']);
$router->post('/admin/kb/articles/{id}/edit', [KbArticleController::class, 'update']);
$router->post('/admin/kb/articles/{id}/delete', [KbArticleController::class, 'destroy']);
$router->post('/admin/kb/articles/{id}/images', [KbImageController::class, 'upload']);
$router->post('/admin/kb/articles/{id}/images/generate', [KbCopilotController::class, 'generateImage']);
$router->post('/admin/kb/articles/{id}/images/{imgId}/delete', [KbImageController::class, 'delete']);
$router->get('/admin/kb/articles/{id}/images/{imgId}', [KbImageController::class, 'serveAdmin']);

$router->get('/kb', [PublicKbController::class, 'index']);
$router->get('/kb/{id}', [PublicKbController::class, 'show']);
$router->post('/kb/{id}/rate', [PublicKbController::class, 'rate']);
$router->get('/kb/{id}/images/{imgId}', [KbImageController::class, 'servePublic']);
