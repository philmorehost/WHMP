<?php

declare(strict_types=1);

use CodeVault\Billing\PromotionController;

/** @var CodeVault\Router $router */

$router->get('/admin/promotions', [PromotionController::class, 'index']);
$router->post('/admin/promotions', [PromotionController::class, 'store']);
$router->post('/admin/promotions/{id}/delete', [PromotionController::class, 'destroy']);
