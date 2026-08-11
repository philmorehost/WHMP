<?php

declare(strict_types=1);

use CodeVault\Seo\AiVisibilityController;
use CodeVault\Seo\SitemapController;
use CodeVault\Support\AnnouncementRssController;

/** @var CodeVault\Router $router */

$router->get('/sitemap.xml', [SitemapController::class, 'index']);
$router->get('/robots.txt', [SitemapController::class, 'robots']);
$router->get('/announcements.rss', [AnnouncementRssController::class, 'index']);
$router->get('/admin/ai-visibility', [AiVisibilityController::class, 'index']);
