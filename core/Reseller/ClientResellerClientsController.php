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
 * so another store's id simply is not found.
 */
final class ClientResellerClientsController
{
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
        [$actor, $store, $redirect] = $this->context();
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
        [$actor, $store, $redirect] = $this->context();
        if ($redirect !== null) {
            return $redirect;
        }

        $storeId = (int) $store['id'];
        $client = $this->directory->client($storeId, (int) ($params['id'] ?? 0));

        if ($client === null) {
            return $this->notFound();
        }

        $clientCurrency = $this->currency->resolveForClient($client);

        return $this->page(trim($client['first_name'] . ' ' . $client['last_name']), 'reseller.client-client', [
            'store' => $store,
            'client' => $client,
            'services' => $this->directory->services($storeId, (int) $client['id']),
            'domains' => $this->directory->domains($storeId, (int) $client['id']),
            'invoices' => $this->directory->invoices($storeId, (int) $client['id']),
            'tickets' => $this->directory->tickets($storeId, (int) $client['id']),
            // Same money rules as the admin client page (ClientController::show):
            // services.amount is already in the customer's currency; invoices carry
            // their own locked currency and rate.
            'serviceMoney' => static fn (float $amount): string => ($clientCurrency['symbol'] ?? '$') . number_format($amount, 2),
            'invoiceMoney' => fn (array $invoice): string => $this->currency->formatDocument(
                (float) $invoice['total'],
                $invoice['currency_id'] !== null ? (int) $invoice['currency_id'] : null,
                (float) ($invoice['currency_rate'] ?? 1.0),
                $clientCurrency
            ),
            'storeError' => ResellerClientManager::storeError($store),
            'notice' => $this->session->pullFlash('reseller_notice'),
            'error' => $this->session->pullFlash('reseller_error'),
        ]);
    }

    public function saveProfile(Request $request, array $params): Response
    {
        return $this->act($params, fn (array $store, array $actor, int $clientId) => $this->manager->updateProfile(
            $store,
            $actor,
            $clientId,
            array_intersect_key((array) $request->input(), ResellerClientManager::PROFILE_FIELDS),
            $request->ip()
        ));
    }

    public function sendPasswordReset(Request $request, array $params): Response
    {
        return $this->act($params, fn (array $store, array $actor, int $clientId) => $this->manager->sendPasswordReset($store, $actor, $clientId, $request->ip()));
    }

    /** Off to the store's website, signed in as the customer (ClientImpersonation). */
    public function login(Request $request, array $params): Response
    {
        [$actor, $store, $redirect] = $this->context();
        if ($redirect !== null) {
            return $redirect;
        }

        $clientId = (int) ($params['id'] ?? 0);
        $result = $this->manager->loginLink($store, $actor, $clientId, $request->ip());

        if (!$result['success'] || $result['url'] === null) {
            $this->session->flash('reseller_error', (string) $result['error']);

            return Response::redirect("/client/reseller/clients/{$clientId}");
        }

        return Response::redirect($result['url']);
    }

    public function suspendService(Request $request, array $params): Response
    {
        return $this->act($params, fn (array $store, array $actor) => $this->manager->suspendService(
            $store,
            $actor,
            (int) ($params['serviceId'] ?? 0),
            (string) $request->input('reason', ''),
            $request->ip()
        ));
    }

    public function unsuspendService(Request $request, array $params): Response
    {
        return $this->act($params, fn (array $store, array $actor) => $this->manager->unsuspendService($store, $actor, (int) ($params['serviceId'] ?? 0), $request->ip()));
    }

    public function terminateService(Request $request, array $params): Response
    {
        return $this->act($params, fn (array $store, array $actor) => $this->manager->terminateService(
            $store,
            $actor,
            (int) ($params['serviceId'] ?? 0),
            (string) $request->input('confirm', '') === '1',
            $request->ip()
        ));
    }

    public function domainAutoRenew(Request $request, array $params): Response
    {
        return $this->act($params, fn (array $store, array $actor) => $this->manager->setDomainAutoRenew(
            $store,
            $actor,
            (int) ($params['domainId'] ?? 0),
            (string) $request->input('auto_renew', '') === '1',
            $request->ip()
        ));
    }

    public function domainLock(Request $request, array $params): Response
    {
        return $this->act($params, fn (array $store, array $actor) => $this->manager->toggleDomainLock($store, $actor, (int) ($params['domainId'] ?? 0), $request->ip()));
    }

    public function domainNameservers(Request $request, array $params): Response
    {
        $ns = [];
        for ($i = 1; $i <= 6; $i++) {
            $ns[] = (string) $request->input("ns{$i}", '');
        }

        return $this->act($params, fn (array $store, array $actor) => $this->manager->saveDomainNameservers($store, $actor, (int) ($params['domainId'] ?? 0), $ns, $request->ip()));
    }

    // ------------------------------------------------------------------

    /**
     * Run one action for the customer in the path, flash its outcome, and go back to
     * the customer's page.
     *
     * The customer in the path must be this store's AND must own the service/domain
     * being acted on — the manager scopes the service/domain to the store, and this
     * checks it is also the customer whose page the form was on, so a form can never
     * act on one customer's service from another customer's page.
     *
     * @param callable(array<string, mixed>, array<string, mixed>, int): array{success: bool, message: string} $action
     */
    private function act(array $params, callable $action): Response
    {
        [$actor, $store, $redirect] = $this->context();
        if ($redirect !== null) {
            return $redirect;
        }

        $clientId = (int) ($params['id'] ?? 0);
        $storeId = (int) $store['id'];

        if ($this->directory->client($storeId, $clientId) === null) {
            return $this->notFound();
        }

        if (isset($params['serviceId'])) {
            $service = $this->directory->service($storeId, (int) $params['serviceId']);
            if ($service === null || (int) $service['client_id'] !== $clientId) {
                return $this->notFound();
            }
        }

        if (isset($params['domainId'])) {
            $domain = $this->directory->domain($storeId, (int) $params['domainId']);
            if ($domain === null || (int) $domain['client_id'] !== $clientId) {
                return $this->notFound();
            }
        }

        $result = $action($store, $actor, $clientId);
        $this->session->flash($result['success'] ? 'reseller_notice' : 'reseller_error', (string) $result['message']);

        return Response::redirect("/client/reseller/clients/{$clientId}");
    }

    /** @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>, 2: ?Response} */
    private function context(): array
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

    private function notFound(): Response
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
