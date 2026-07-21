<?php

declare(strict_types=1);

use CodeVault\CpanelTools\CpanelToolsController;

/** @var CodeVault\Router $router */

$router->get('/client/services/{id}/cpanel-tools', [CpanelToolsController::class, 'index']);
$router->post('/client/services/{id}/cpanel-tools/email', [CpanelToolsController::class, 'storeEmail']);
$router->post('/client/services/{id}/cpanel-tools/email/delete', [CpanelToolsController::class, 'destroyEmail']);
$router->post('/client/services/{id}/cpanel-tools/ftp', [CpanelToolsController::class, 'storeFtp']);
$router->post('/client/services/{id}/cpanel-tools/ftp/delete', [CpanelToolsController::class, 'destroyFtp']);
$router->post('/client/services/{id}/cpanel-tools/database', [CpanelToolsController::class, 'storeDatabase']);
$router->post('/client/services/{id}/cpanel-tools/database/delete', [CpanelToolsController::class, 'destroyDatabase']);
$router->post('/client/services/{id}/cpanel-tools/dns', [CpanelToolsController::class, 'storeDns']);
$router->post('/client/services/{id}/cpanel-tools/dns/delete', [CpanelToolsController::class, 'destroyDns']);

$router->post('/client/services/{id}/cpanel-tools/email/forwarder', [CpanelToolsController::class, 'storeForwarder']);
$router->post('/client/services/{id}/cpanel-tools/email/forwarder/delete', [CpanelToolsController::class, 'destroyForwarder']);
$router->post('/client/services/{id}/cpanel-tools/email/autoresponder', [CpanelToolsController::class, 'storeAutoresponder']);
$router->post('/client/services/{id}/cpanel-tools/email/autoresponder/delete', [CpanelToolsController::class, 'destroyAutoresponder']);

$router->post('/client/services/{id}/cpanel-tools/domains/addon', [CpanelToolsController::class, 'storeAddonDomain']);
$router->post('/client/services/{id}/cpanel-tools/domains/addon/delete', [CpanelToolsController::class, 'destroyAddonDomain']);
$router->post('/client/services/{id}/cpanel-tools/domains/subdomain', [CpanelToolsController::class, 'storeSubdomain']);
$router->post('/client/services/{id}/cpanel-tools/domains/subdomain/delete', [CpanelToolsController::class, 'destroySubdomain']);
$router->post('/client/services/{id}/cpanel-tools/domains/redirect', [CpanelToolsController::class, 'storeRedirect']);
$router->post('/client/services/{id}/cpanel-tools/domains/redirect/delete', [CpanelToolsController::class, 'destroyRedirect']);

$router->post('/client/services/{id}/cpanel-tools/cron', [CpanelToolsController::class, 'storeCron']);
$router->post('/client/services/{id}/cpanel-tools/cron/delete', [CpanelToolsController::class, 'destroyCron']);

$router->post('/client/services/{id}/cpanel-tools/ssh', [CpanelToolsController::class, 'storeSshKey']);
$router->post('/client/services/{id}/cpanel-tools/ssh/delete', [CpanelToolsController::class, 'destroySshKey']);
$router->post('/client/services/{id}/cpanel-tools/ssh/authorize', [CpanelToolsController::class, 'authorizeSshKey']);

$router->post('/client/services/{id}/cpanel-tools/sso/{app}', [CpanelToolsController::class, 'sso']);
