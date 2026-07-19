<?php

declare(strict_types=1);

use CodeVault\Downloads\DownloadCategoryController;
use CodeVault\Downloads\DownloadController;
use CodeVault\Downloads\PublicDownloadController;

/** @var CodeVault\Router $router */

$router->get('/admin/downloads/categories', [DownloadCategoryController::class, 'index']);
$router->post('/admin/downloads/categories', [DownloadCategoryController::class, 'store']);
$router->post('/admin/downloads/categories/{id}/delete', [DownloadCategoryController::class, 'destroy']);

$router->get('/admin/downloads', [DownloadController::class, 'index']);
$router->get('/admin/downloads/create', [DownloadController::class, 'createForm']);
$router->post('/admin/downloads', [DownloadController::class, 'store']);
$router->post('/admin/downloads/{id}/delete', [DownloadController::class, 'destroy']);

$router->get('/downloads', [PublicDownloadController::class, 'index']);
$router->get('/downloads/{id}', [PublicDownloadController::class, 'download']);
