<?php

declare(strict_types=1);

use CodeVault\Affiliates\AffiliateAdminController;
use CodeVault\Affiliates\AffiliateController;

/** @var CodeVault\Router $router */

$router->get('/client/affiliate', [AffiliateController::class, 'index']);
$router->post('/client/affiliate/join', [AffiliateController::class, 'join']);
$router->post('/client/affiliate/payout', [AffiliateController::class, 'requestPayout']);

$router->get('/admin/affiliates', [AffiliateAdminController::class, 'index']);
$router->post('/admin/affiliates/{id}/status', [AffiliateAdminController::class, 'setStatus']);
$router->get('/admin/affiliates/payouts', [AffiliateAdminController::class, 'payouts']);
$router->post('/admin/affiliates/payouts/{id}/approve', [AffiliateAdminController::class, 'approvePayout']);
$router->post('/admin/affiliates/payouts/{id}/reject', [AffiliateAdminController::class, 'rejectPayout']);
