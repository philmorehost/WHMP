<?php

declare(strict_types=1);

use CodeVault\Knowledgebase\KbArticleController;
use CodeVault\Knowledgebase\KbCategoryController;
use CodeVault\Knowledgebase\PublicKbController;

/** @var CodeVault\Router $router */

$router->get('/admin/kb/categories', [KbCategoryController::class, 'index']);
$router->post('/admin/kb/categories', [KbCategoryController::class, 'store']);
$router->post('/admin/kb/categories/{id}/delete', [KbCategoryController::class, 'destroy']);

$router->get('/admin/kb/articles', [KbArticleController::class, 'index']);
$router->get('/admin/kb/articles/create', [KbArticleController::class, 'createForm']);
$router->post('/admin/kb/articles', [KbArticleController::class, 'store']);
$router->get('/admin/kb/articles/{id}/edit', [KbArticleController::class, 'editForm']);
$router->post('/admin/kb/articles/{id}/edit', [KbArticleController::class, 'update']);
$router->post('/admin/kb/articles/{id}/delete', [KbArticleController::class, 'destroy']);

$router->get('/kb', [PublicKbController::class, 'index']);
$router->get('/kb/{id}', [PublicKbController::class, 'show']);
$router->post('/kb/{id}/rate', [PublicKbController::class, 'rate']);
