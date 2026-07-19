<?php

declare(strict_types=1);

use CodeVault\Clients\ClientAuthController;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Container;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;

/** @var CodeVault\Router $router */

$router->get('/client/login', [ClientAuthController::class, 'loginForm']);
$router->post('/client/login', [ClientAuthController::class, 'login']);
$router->get('/client/return-to-admin', [ClientAuthController::class, 'returnToAdmin']);
$router->get('/client/login/2fa', [ClientAuthController::class, 'twoFactorForm']);
$router->post('/client/login/2fa', [ClientAuthController::class, 'verifyTwoFactor']);
$router->get('/client/register', [ClientAuthController::class, 'registerForm']);
$router->post('/client/register', [ClientAuthController::class, 'register']);
$router->post('/client/logout', [ClientAuthController::class, 'logout']);

$router->get('/client/forgot-password', [ClientAuthController::class, 'forgotPasswordForm']);
$router->post('/client/forgot-password', [ClientAuthController::class, 'sendResetLink']);
$router->get('/client/password/reset/{token}', [ClientAuthController::class, 'resetPasswordForm']);
$router->post('/client/password/reset/{token}', [ClientAuthController::class, 'resetPassword']);

$router->get('/client/dashboard', function (Request $request, array $params, Container $container): Response {
    /** @var ClientAuthGuard $guard */
    $guard = $container->make(ClientAuthGuard::class);
    $client = $guard->currentClient();

    if ($client === null) {
        return Response::redirect('/client/login');
    }

    /** @var CodeVault\Database $db */
    $db = $container->make(CodeVault\Database::class);
    $clientId = (int) $client['id'];

    $servicesCount = (int) ($db->selectOne("SELECT COUNT(*) AS c FROM services WHERE client_id = ? AND status = 'active'", [$clientId])['c'] ?? 0);
    $domainsCount = (int) ($db->selectOne("SELECT COUNT(*) AS c FROM domains WHERE client_id = ? AND status = 'active'", [$clientId])['c'] ?? 0);
    $invoicesCount = (int) ($db->selectOne("SELECT COUNT(*) AS c FROM invoices WHERE client_id = ? AND status = 'unpaid'", [$clientId])['c'] ?? 0);
    $ticketsCount = (int) ($db->selectOne("SELECT COUNT(*) AS c FROM tickets WHERE client_id = ? AND status IN ('open', 'customer-reply', 'on-hold')", [$clientId])['c'] ?? 0);

    // Fetch unpaid invoices
    $unpaidInvoices = $db->select("SELECT * FROM invoices WHERE client_id = ? AND status = 'unpaid' ORDER BY created_at DESC LIMIT 5", [$clientId]);
    // Fetch active services
    $activeServices = $db->select("SELECT * FROM services WHERE client_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 5", [$clientId]);

    /** @var View $view */
    $view = $container->make(View::class);

    $content = $view->render('client-auth.dashboard', [
        'client' => $client,
        'servicesCount' => $servicesCount,
        'domainsCount' => $domainsCount,
        'invoicesCount' => $invoicesCount,
        'ticketsCount' => $ticketsCount,
        'unpaidInvoices' => $unpaidInvoices,
        'activeServices' => $activeServices,
    ]);

    return Response::html($view->render('layouts.client', [
        'title' => 'My Account',
        'content' => $content,
    ]));
});
