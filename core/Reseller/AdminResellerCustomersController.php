<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Billing\CurrencyService;
use CodeVault\Clients\ClientRepository;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

/**
 * A reseller's customers, from the RESELLER'S page in the admin panel:
 * /admin/resellers/{clientId}/customers.
 *
 * STRICT RESELLER ISOLATION
 *
 * A store's customers are the store's. They are not on the admin's Clients list, and
 * the admin's client pages redirect here for them (Kernel). An admin who needs to look
 * after one comes through the reseller first — this page — or signs in to the
 * reseller's own account (loginAsReseller) and uses the reseller's Customers area.
 *
 * The store is resolved from the reseller (the owner's user ID in the path), and every
 * customer, service and domain id is looked up THROUGH that store
 * (ResellerClientDirectory), so a customer of another reseller is simply not found.
 * The actions are the reseller's own (StoreCustomerActions → ResellerClientManager),
 * run with an admin actor: logged as the admin, allowed while the store is
 * suspended, and able to lift any suspension.
 */
final class AdminResellerCustomersController
{
    use StoreCustomerActions;

    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly SessionManager $session,
        private readonly ResellerStoreRepository $stores,
        private readonly ClientRepository $clients,
        private readonly ResellerClientDirectory $directory,
        private readonly ResellerClientManager $manager,
        private readonly CurrencyService $currency,
        private readonly ActivityLogger $activity
    ) {
    }

    public function index(Request $request, array $params): Response
    {
        [$actor, $store, $redirect] = $this->customerContext($params);
        if ($redirect !== null) {
            return $redirect;
        }

        $filter = (string) $request->query('filter', 'all');
        $filter = in_array($filter, ['all', 'active', 'suspended', 'unpaid'], true) ? $filter : 'all';
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $page = max(1, (int) $request->query('page', 1));
        $storeId = (int) $store['id'];

        return $this->page('Reseller customers', 'reseller.client-clients', [
            'store' => $store,
            'owner' => $this->clients->find((int) $store['client_id']),
            'mode' => 'admin',
            'baseUrl' => $this->base($store),
            'summary' => $this->directory->summary($storeId),
            'results' => $this->directory->clients($storeId, $search, $filter, $page),
            'orders' => $this->directory->storeOrders($storeId),
            'orderMoney' => fn (array $order): string => $this->currency->formatDocument(
                (float) $order['total'],
                $order['currency_id'] !== null ? (int) $order['currency_id'] : null,
                (float) ($order['currency_rate'] ?? 1.0),
                null
            ),
            'search' => $search,
            'filter' => $filter,
            'storeError' => null,
            'notice' => $this->session->pullFlash('reseller_notice'),
            'error' => $this->session->pullFlash('reseller_error'),
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        [$actor, $store, $redirect] = $this->customerContext($params);
        if ($redirect !== null) {
            return $redirect;
        }

        $client = $this->directory->client((int) $store['id'], (int) ($params['id'] ?? 0));

        if ($client === null) {
            return $this->customerNotFound($store);
        }

        return $this->page('Reseller customer', 'reseller.client-client', $this->customerPageData($store, $client) + [
            'actor' => $actor,
            'owner' => $this->clients->find((int) $store['client_id']),
            'mode' => 'admin',
            'baseUrl' => $this->base($store),
            // An admin is never paused by the store's own suspension.
            'storeError' => null,
            'storeNote' => ResellerClientManager::storeError($store) !== null
                ? 'This reseller\'s store is suspended. The reseller cannot change its customers; you still can.'
                : null,
        ]);
    }

    /**
     * Sign in to the RESELLER'S own account (their client area on the platform), to
     * work in their reseller control panel exactly as they would. Same mechanism as
     * the admin's "Login as Client" for a platform customer — which a reseller is.
     */
    public function loginAsReseller(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $ownerId = (int) ($params['clientId'] ?? 0);
        $owner = $this->clients->find($ownerId);
        $store = $this->stores->forClient($ownerId);

        if ($owner === null || $store === null || (int) ($owner['reseller_id'] ?? 0) > 0) {
            return Response::redirect('/admin/resellers');
        }

        $admin = $this->guard->currentAdmin();

        if ($admin !== null) {
            $this->session->set('original_admin_id', $admin['id']);
        }

        $this->session->set('client_id', $ownerId);

        $this->activity->log(
            'admin',
            $admin !== null ? (int) $admin['id'] : null,
            'admin.reseller_login',
            'client',
            $ownerId,
            'Signed in to the account of reseller ID ' . (int) $store['id'] . ' (user ID ' . $ownerId . ')',
            $request->ip()
        );

        $target = (string) $request->input('to', '');

        return Response::redirect($target === 'customers' ? '/client/reseller/clients' : '/client/reseller');
    }

    protected function customerContext(array $params): array
    {
        if ($denied = $this->requirePermission()) {
            return [null, null, $denied];
        }

        $admin = $this->guard->currentAdmin();
        $store = $this->stores->forClient((int) ($params['clientId'] ?? 0));

        if ($admin === null || $store === null) {
            return [null, null, Response::redirect('/admin/resellers')];
        }

        return [ResellerClientManager::adminActor($admin), $store, null];
    }

    protected function customerUrl(array $store, int $clientId): string
    {
        return $this->base($store) . '/' . $clientId;
    }

    protected function customerNotFound(?array $store): Response
    {
        $back = $store === null ? '/admin/resellers' : $this->base($store);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'Customer not found',
            'content' => '<div class="cv-card"><h1 class="cv-card__title">Customer not found</h1>'
                . '<p>That user is not a customer of this reseller.</p>'
                . '<p><a class="cv-btn" href="' . e($back) . '">Back to this reseller\'s customers</a></p></div>',
        ]), 404);
    }

    /** @param array<string, mixed> $store */
    private function base(array $store): string
    {
        return '/admin/resellers/' . (int) $store['client_id'] . '/customers';
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::RESELLERS_MANAGE)) {
            return Response::html('403 Forbidden — missing resellers.manage permission', 403);
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function page(string $title, string $template, array $data): Response
    {
        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — ' . $title,
            'content' => $this->view->render($template, $data),
        ]));
    }
}
