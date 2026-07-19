<?php

declare(strict_types=1);

use CodeVault\Localization\LanguageController;
use CodeVault\Localization\LanguageSwitchController;
use CodeVault\Localization\TranslationOverrideController;

/** @var CodeVault\Router $router */

$router->get('/admin/languages', [LanguageController::class, 'index']);
$router->post('/admin/languages/{id}/toggle-active', [LanguageController::class, 'toggleActive']);
$router->post('/admin/languages/{id}/default', [LanguageController::class, 'setDefault']);
$router->get('/admin/languages/{id}/translations', [TranslationOverrideController::class, 'edit']);
$router->post('/admin/languages/{id}/translations', [TranslationOverrideController::class, 'update']);

$router->post('/language', [LanguageSwitchController::class, 'select']);
