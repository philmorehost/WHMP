<?php

declare(strict_types=1);

use CodeVault\Marketing\CampaignTrackingController;
use CodeVault\Marketing\MailCampaignController;

/** @var CodeVault\Router $router */

$router->get('/admin/campaigns', [MailCampaignController::class, 'index']);
$router->post('/admin/campaigns', [MailCampaignController::class, 'store']);
$router->get('/admin/campaigns/{id}', [MailCampaignController::class, 'show']);
$router->post('/admin/campaigns/{id}/send', [MailCampaignController::class, 'send']);

$router->get('/campaigns/track/{token}', [CampaignTrackingController::class, 'pixel']);
