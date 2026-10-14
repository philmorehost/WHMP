<?php

declare(strict_types=1);

namespace CodeVault\UsernameChanger;

use CodeVault\Billing\CurrencyRepository;
use CodeVault\Billing\CurrencyService;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Modules\AddonModuleRepository;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\View;

/**
 * Reseller panel → Username requests.
 *
 * A store sees ONLY its own customers' requests (scoped by reseller_id), can
 * approve/decline when its store policy asks for its approval, can make the
 * rules stricter for its customers, and — when the super admin has switched
 * payment on and allowed resale — sets the price its customers pay. It never
 * sees server details and cannot run renames itself.
 */
final class ResellerUsernameController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly SessionManager $session,
        private readonly AddonModuleRepository $addons,
        private readonly ResellerStoreRepository $stores,
        private readonly UsernameChangeRepository $requests,
        private readonly UsernameChangeService $service,
        private readonly UsernameChangerSettings $settings,
        private readonly PolicyResolver $policies,
        private readonly ?CurrencyService $currency = null,
        private readonly ?CurrencyRepository $currencies = null
    ) {
    }

    public function index(Request $request): Response
    {
        [$store, $client, $deny] = $this->context();

        if ($deny !== null) {
            return $deny;
        }

        $status = (string) $request->query('status', 'open');
        $q = (string) $request->query('q', '');
        $policy = $this->requests->policy('store', (int) $store['id']) ?? [];
        $currency = $this->currency?->resolveForClient($client);
        $fmt = function (float $amount) use ($currency): string {
            return $this->currency !== null && $currency !== null
                ? $this->currency->format($amount, $currency)
                : number_format($amount, 2);
        };
        $cost = $this->policies->storeCost($store);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'Reseller Area — Username requests',
            'content' => $this->view->render('username-changer.reseller', [
                'rows' => $this->requests->search($status === 'all' ? null : $status, $q, (int) $store['id']),
                'counts' => $this->requests->countsByStatus((int) $store['id']),
                'status' => $status,
                'q' => $q,
                'policy' => $policy,
                'global' => [
                    'max_changes' => $this->settings->maxChanges(),
                    'cooldown_days' => $this->settings->cooldownDays(),
                    'approval' => $this->settings->approval(),
                    'store_approval_allowed' => $this->settings->storeApprovalAllowed(),
                ],
                'fee' => [
                    'enabled' => $this->settings->feeEnabled(),
                    'resale' => $this->settings->storePricing(),
                    'cost' => $cost,
                    'costLabel' => $fmt($cost),
                    'price' => ($policy['fee'] ?? null) === null ? null : (float) $policy['fee'],
                    'priceLabel' => ($policy['fee'] ?? null) === null ? null : $fmt(max($cost, (float) $policy['fee'])),
                    'currencyCode' => (string) ($currency['code'] ?? ''),
                    'catalogCode' => (string) ($this->currencies?->pricing()['code'] ?? ''),
                ],
                'notice' => $this->session->pullFlash('ucn_notice'),
                'error' => $this->session->pullFlash('ucn_error'),
            ]),
        ]));
    }

    public function decide(Request $request, array $params): Response
    {
        [$store, $client, $deny] = $this->context();

        if ($deny !== null) {
            return $deny;
        }

        $id = (int) $params['id'];
        $result = (string) $params['action'] === 'approve'
            ? $this->service->approve($id, 'reseller', (int) $client['id'], $request->ip(), (int) $store['id'])
            : $this->service->decline($id, (string) $request->input('reason', ''), 'reseller', (int) $client['id'], $request->ip(), (int) $store['id']);

        $this->session->flash($result['ok'] ? 'ucn_notice' : 'ucn_error', $result['message']);

        return Response::redirect('/client/reseller/username-requests');
    }

    public function savePolicy(Request $request): Response
    {
        [$store, , $deny] = $this->context();

        if ($deny !== null) {
            return $deny;
        }

        $num = static fn ($v): ?int => $v === '' || $v === null ? null : max(0, (int) $v);
        $fields = [
            'enabled' => $request->input('enabled') === '0' ? 0 : null,
            'max_changes' => $num($request->input('max_changes', '')),
            'cooldown_days' => $num($request->input('cooldown_days', '')),
            'approval' => $request->input('approval') === 'reseller' && $this->settings->storeApprovalAllowed() ? 'reseller' : null,
            'allow_db_rename' => $request->input('allow_db_rename') === '0' ? 0 : null,
        ];

        // The resale price: never below what the store owes for the change.
        if ($this->settings->feeEnabled() && $this->settings->storePricing()) {
            $raw = trim((string) $request->input('fee', ''));

            if ($raw === '') {
                $fields['fee'] = null;
            } else {
                $price = round((float) str_replace(',', '', $raw), 2);
                $cost = $this->policies->storeCost($store);

                if ($price < $cost) {
                    $this->session->flash('ucn_error', 'Your price cannot be lower than your cost of ' . number_format($cost, 2) . '.');

                    return Response::redirect('/client/reseller/username-requests');
                }

                $fields['fee'] = $price;
            }
        }

        $this->requests->savePolicy('store', (int) $store['id'], $fields);
        $this->session->flash('ucn_notice', 'Saved. Your rules apply to your customers from now on.');

        return Response::redirect('/client/reseller/username-requests');
    }

    /** @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>, 2: ?Response} */
    private function context(): array
    {
        if (!$this->addons->isActive(UsernameChangeCronJob::SLUG) || !$this->settings->storesAllowed()) {
            return [null, null, Response::html('404 Not Found', 404)];
        }

        $client = $this->guard->currentClient();

        if ($client === null) {
            return [null, null, Response::redirect('/client/login')];
        }

        $store = $this->stores->forClient((int) $client['id']);

        if ($store === null) {
            $this->session->flash('reseller_error', 'Open your store first.');

            return [null, null, Response::redirect('/client/reseller/store')];
        }

        return [$store, $client, null];
    }
}
