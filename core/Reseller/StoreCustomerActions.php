<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Request;
use CodeVault\Response;

/**
 * The actions on one store customer, shared by the two places they can be taken from:
 *
 *  - the reseller's own Customers area (ClientResellerClientsController), and
 *  - the reseller's page in the admin panel (AdminResellerCustomersController).
 *
 * Both go through ResellerClientManager with the store resolved by the controller —
 * never from the request — and both check that the customer in the path is that
 * store's and owns the service/domain being acted on. The using class supplies who
 * is acting, which store, and where to send the browser back to.
 */
trait StoreCustomerActions
{
    /**
     * The actor, the store, or a response to return instead.
     *
     * @param array<string, mixed> $params
     * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>, 2: ?Response}
     */
    abstract protected function customerContext(array $params): array;

    /** @param array<string, mixed> $store */
    abstract protected function customerUrl(array $store, int $clientId): string;

    /** @param array<string, mixed> $store */
    abstract protected function customerNotFound(?array $store): Response;

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
        [$actor, $store, $redirect] = $this->customerContext($params);
        if ($redirect !== null) {
            return $redirect;
        }

        $clientId = (int) ($params['id'] ?? 0);
        $result = $this->manager->loginLink($store, $actor, $clientId, $request->ip());

        if (!$result['success'] || $result['url'] === null) {
            $this->session->flash('reseller_error', (string) $result['error']);

            return Response::redirect($this->customerUrl($store, $clientId));
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

    /**
     * Run one action for the customer in the path, flash its outcome, and go back to
     * the customer's page.
     *
     * The customer in the path must be this store's AND must own the service/domain
     * being acted on — the manager scopes the service/domain to the store, and this
     * checks it is also the customer whose page the form was on, so a form can never
     * act on one customer's service from another customer's page.
     *
     * @param array<string, mixed> $params
     * @param callable(array<string, mixed>, array<string, mixed>, int): array{success: bool, message: string} $action
     */
    private function act(array $params, callable $action): Response
    {
        [$actor, $store, $redirect] = $this->customerContext($params);
        if ($redirect !== null) {
            return $redirect;
        }

        $clientId = (int) ($params['id'] ?? 0);
        $storeId = (int) $store['id'];

        if ($this->directory->client($storeId, $clientId) === null) {
            return $this->customerNotFound($store);
        }

        if (isset($params['serviceId'])) {
            $service = $this->directory->service($storeId, (int) $params['serviceId']);
            if ($service === null || (int) $service['client_id'] !== $clientId) {
                return $this->customerNotFound($store);
            }
        }

        if (isset($params['domainId'])) {
            $domain = $this->directory->domain($storeId, (int) $params['domainId']);
            if ($domain === null || (int) $domain['client_id'] !== $clientId) {
                return $this->customerNotFound($store);
            }
        }

        $result = $action($store, $actor, $clientId);
        $this->session->flash($result['success'] ? 'reseller_notice' : 'reseller_error', (string) $result['message']);

        return Response::redirect($this->customerUrl($store, $clientId));
    }

    /**
     * Everything the customer page shows, read through the store.
     *
     * @param array<string, mixed> $store
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    private function customerPageData(array $store, array $client): array
    {
        $storeId = (int) $store['id'];
        $clientCurrency = $this->currency->resolveForClient($client);

        return [
            'store' => $store,
            'client' => $client,
            'services' => $this->directory->services($storeId, (int) $client['id']),
            'domains' => $this->directory->domains($storeId, (int) $client['id']),
            'invoices' => $this->directory->invoices($storeId, (int) $client['id']),
            'tickets' => $this->directory->tickets($storeId, (int) $client['id']),
            'orders' => $this->directory->orders($storeId, (int) $client['id']),
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
            'notice' => $this->session->pullFlash('reseller_notice'),
            'error' => $this->session->pullFlash('reseller_error'),
        ];
    }
}
