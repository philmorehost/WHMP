<?php

declare(strict_types=1);

use CodeVault\Reports\ReportController;
use CodeVault\Reports\ServerAuditController;
use CodeVault\Reports\ExpiringReminderController;

/** @var CodeVault\Router $router */

$router->get('/admin/reports', [ReportController::class, 'index']);
$router->get('/admin/reports/income', [ReportController::class, 'income']);
$router->get('/admin/reports/tax-liability', [ReportController::class, 'taxLiability']);
$router->get('/admin/reports/aged-debtors', [ReportController::class, 'agedDebtors']);
$router->get('/admin/reports/product-breakdown', [ReportController::class, 'productBreakdown']);
$router->get('/admin/reports/affiliate-payouts', [ReportController::class, 'affiliatePayouts']);
$router->post('/admin/reports/modules/{slug}/activate', [ReportController::class, 'activateModule']);
$router->post('/admin/reports/modules/{slug}/deactivate', [ReportController::class, 'deactivateModule']);
$router->get('/admin/reports/modules/{slug}', [ReportController::class, 'runModule']);

$router->get('/admin/server-audit', [ServerAuditController::class, 'audit']);

$router->get('/admin/expiring-reminders', [ExpiringReminderController::class, 'index']);
$router->post('/admin/expiring-reminders/generate', [ExpiringReminderController::class, 'generate']);
$router->post('/admin/expiring-reminders/send', [ExpiringReminderController::class, 'send']);
