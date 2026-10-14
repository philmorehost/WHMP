<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Billing\CurrencyService;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\View;

/**
 * The reseller's customer list and customer pages: /client/reseller/clients.
 *
 * NO STORE ID FROM THE REQUEST, like every page in the reseller area: the store is the
 * signed-in client's own (ResellerStoreRepository::forClient). The customer, service
 * and domain ids in the paths are looked up THROUGH that store (ResellerClientDirectory),
 * so another store's id simply is not found. The actions themselves are shared with the
 * admin's view of the same store (StoreCustomerActions).
 */
final class ClientResellerClientsController
{
    use StoreCustomerActions;

    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly SessionManager $session,
        private readonly ResellerStoreRepository $stores,
        private readonly ResellerClientDirectory $directory,
        private readonly ResellerClientManager $manager,
        private readonly CurrencyService $currency
    ) {
    }

    public function index(Request $request): Response
    {
        [$actor, $store, $redirect] = $this->customerContext([]);
        if ($redirect !== null) {
            return $redirect;
        }

        $filter = (string) $request->query('filter', 'all');
        $filter = in_array($filter, ['all', 'active', 'suspended', 'unpaid'], true) ? $filter : 'all';
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $page = max(1, (int) $request->query('page', 1));

        return $this->page('Your customers', 'reseller.client-clients', [
            'store' => $store,
            'summary' => $this->directory->summary((int) $store['id']),
            'results' => $this->directory->clients((int) $store['id'], $search, $filter, $page),
            'search' => $search,
            'filter' => $filter,
            'storeError' => ResellerClientManager::storeError($store),
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

        return $this->page(trim($client['first_name'] . ' ' . $client['last_name']), 'reseller.client-client', $this->customerPageData($store, $client) + [
            'actor' => $actor,
            'storeError' => ResellerClientManager::storeError($store),
        ]);
    }

    protected function customerContext(array $params): array
    {
        $actor = $this->guard->currentClient();

        if ($actor === null) {
            return [null, null, Response::redirect('/client/login')];
        }

        $store = $this->stores->forClient((int) $actor['id']);

        if ($store === null) {
            return [$actor, null, Response::redirect('/client/reseller')];
        }

        return [$actor, $store, null];
    }

    protected function customerUrl(array $store, int $clientId): string
    {
        return "/client/reseller/clients/{$clientId}";
    }

    protected function customerNotFound(?array $store): Response
    {
        return Response::html($this->view->render('layouts.client', [
            'title' => 'Customer not found',
            'content' => '<div class="cv-card"><h1 class="cv-card__title">Customer not found</h1>'
                . '<p>That customer is not one of your store\'s customers.</p>'
                . '<p><a class="cv-btn" href="/client/reseller/clients">Back to your customers</a></p></div>',
        ]), 404);
    }

    /** @param array<string, mixed> $data */
    private function page(string $title, string $template, array $data): Response
    {
        return Response::html($this->view->render('layouts.client', [
            'title' => $title,
            'content' => $this->view->render($template, $data),
        ]));
    }
}
