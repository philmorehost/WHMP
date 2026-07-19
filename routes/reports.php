<?php

declare(strict_types=1);

use CodeVault\Reports\ReportController;

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
