<?php

declare(strict_types=1);

use CodeVault\Billing\CurrencyService;
use CodeVault\Clients\ClientAuthController;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Container;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;

/** @var CodeVault\Router $router */

$router->get('/client', function (Request $request, array $params, Container $container): Response {
    /** @var ClientAuthGuard $guard */
    $guard = $container->make(ClientAuthGuard::class);
    if ($guard->check()) {
        return Response::redirect('/client/dashboard');
    }
    return Response::redirect('/client/login');
});

$router->get('/client/login', [ClientAuthController::class, 'loginForm']);
$router->post('/client/login', [ClientAuthController::class, 'login']);
$router->get('/client/return-to-admin', [ClientAuthController::class, 'returnToAdmin']);
$router->get('/client/login/2fa', [ClientAuthController::class, 'twoFactorForm']);
$router->post('/client/login/2fa', [ClientAuthController::class, 'verifyTwoFactor']);
$router->get('/client/recover-pin', [ClientAuthController::class, 'recoverPinForm']);
$router->post('/client/recover-pin', [ClientAuthController::class, 'recoverPin']);
$router->get('/client/set-pin', [ClientAuthController::class, 'setPinForm']);
$router->post('/client/set-pin', [ClientAuthController::class, 'setPin']);
$router->get('/client/register', [ClientAuthController::class, 'registerForm']);
$router->post('/client/register', [ClientAuthController::class, 'register']);
$router->get('/client/register/verify', [ClientAuthController::class, 'registerVerifyForm']);
$router->post('/client/register/verify', [ClientAuthController::class, 'registerVerify']);
$router->post('/client/register/resend-otp', [ClientAuthController::class, 'registerResendOtp']);
$router->post('/client/logout', [ClientAuthController::class, 'logout']);

$router->get('/client/forgot-password', [ClientAuthController::class, 'forgotPasswordForm']);
$router->post('/client/forgot-password', [ClientAuthController::class, 'sendResetLink']);
$router->get('/client/password/reset/{token}', [ClientAuthController::class, 'resetPasswordForm']);
$router->post('/client/password/reset/{token}', [ClientAuthController::class, 'resetPassword']);

$router->get('/client/auth/google', [ClientAuthController::class, 'googleRedirect']);
$router->get('/client/auth/google/callback', [ClientAuthController::class, 'googleCallback']);

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
    // Fetch recent tickets
    $recentTickets = $db->select("SELECT * FROM tickets WHERE client_id = ? ORDER BY updated_at DESC LIMIT 8", [$clientId]);

    // A standalone domain registration rides on the hidden "Domain
    // Registration" carrier service (see DomainService::carrierProductId()),
    // so it appears in "Your Active Products & Services" next to hosting
    // services. Tag each such service with the domain row it was created for
    // so the dashboard links it to the domain manager (/client/domains/{id})
    // instead of a service page that would misrender a domain as if it were a
    // cPanel hosting service.
    $carrierProductId = (int) ($db->selectOne("SELECT id FROM products WHERE name = 'Domain Registration' AND status = 'hidden' LIMIT 1")['id'] ?? 0);
    $domainIdsByName = [];

    if ($carrierProductId > 0) {
        foreach ($db->select('SELECT id, domain_name FROM domains WHERE client_id = ?', [$clientId]) as $domain) {
            $domainIdsByName[strtolower((string) $domain['domain_name'])] = (int) $domain['id'];
        }
    }

    foreach ($activeServices as &$svc) {
        $svc['domain_id'] = null;

        if ($carrierProductId > 0 && (int) $svc['product_id'] === $carrierProductId) {
            $name = strtolower((string) ($svc['domain'] ?? ''));

            if ($name !== '' && isset($domainIdsByName[$name])) {
                $svc['domain_id'] = $domainIdsByName[$name];
            }
        }
    }
    unset($svc);

    /** @var CurrencyService $currencyService */
    $currencyService = $container->make(CurrencyService::class);
    $currency = $currencyService->resolveForClient($client);

    /** @var View $view */
    $view = $container->make(View::class);

    /** @var CodeVault\Ai\AiSettings $aiSettings */
    $aiSettings = $container->make(CodeVault\Ai\AiSettings::class);

    /** @var \CodeVault\Billing\ClientCreditRepository $creditRepo */
    $creditRepo = $container->make(\CodeVault\Billing\ClientCreditRepository::class);
    $creditBalance = $creditRepo->balance($clientId);

    $content = $view->render('client-auth.dashboard', [
        'client' => $client,
        'servicesCount' => $servicesCount,
        'domainsCount' => $domainsCount,
        'invoicesCount' => $invoicesCount,
        'ticketsCount' => $ticketsCount,
        'unpaidInvoices' => $unpaidInvoices,
        'activeServices' => $activeServices,
        'recentTickets' => $recentTickets,
        'currency' => $currency,
        'currencyService' => $currencyService,
        'creditBalance' => $creditBalance,
        'aiCopilotEnabled' => $aiSettings->isFeatureEnabled('onboarding') && $aiSettings->hasKey(),
    ]);

    return Response::html($view->render('layouts.client', [
        'title' => 'My Account',
        'content' => $content,
    ]));
});
