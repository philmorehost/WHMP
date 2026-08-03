<?php

declare(strict_types=1);

use CodeVault\Marketing\CampaignCopilotController;
use CodeVault\Marketing\CampaignTrackingController;
use CodeVault\Marketing\MailCampaignController;
use CodeVault\Marketing\PromoBannerController;
use CodeVault\Marketing\PromoBannerCopilotController;
use CodeVault\Marketing\PromoBannerPublicController;

/** @var CodeVault\Router $router */

$router->get('/admin/campaigns', [MailCampaignController::class, 'index']);
$router->post('/admin/campaigns', [MailCampaignController::class, 'store']);
// Registered before the {id} routes so the literal path is never shadowed by
// a parameter pattern as more campaign routes are added.
$router->post('/admin/campaigns/copilot', [CampaignCopilotController::class, 'generate']);
$router->get('/admin/campaigns/{id}', [MailCampaignController::class, 'show']);
$router->post('/admin/campaigns/{id}/send', [MailCampaignController::class, 'send']);
$router->post('/admin/campaigns/{id}/pause', [MailCampaignController::class, 'pause']);
$router->post('/admin/campaigns/{id}/resume', [MailCampaignController::class, 'resume']);
$router->post('/admin/campaigns/{id}/update', [MailCampaignController::class, 'update']);

$router->get('/campaigns/track/{token}', [CampaignTrackingController::class, 'pixel']);

$router->get('/admin/promo-banners', [PromoBannerController::class, 'index']);
$router->post('/admin/promo-banners', [PromoBannerController::class, 'store']);
$router->post('/admin/promo-banners/copilot', [PromoBannerCopilotController::class, 'generate']);
$router->post('/admin/promo-banners/{id}/update', [PromoBannerController::class, 'update']);
$router->post('/admin/promo-banners/{id}/pause', [PromoBannerController::class, 'pause']);
$router->post('/admin/promo-banners/{id}/resume', [PromoBannerController::class, 'resume']);
$router->post('/admin/promo-banners/{id}/delete', [PromoBannerController::class, 'destroy']);

// Public — anyone clicking a banner's Apply button, logged in or not.
$router->post('/promo-banners/{id}/apply', [PromoBannerPublicController::class, 'apply']);
