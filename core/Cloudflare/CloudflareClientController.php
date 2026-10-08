<?php

declare(strict_types=1);

namespace CodeVault\Cloudflare;

use CodeVault\Billing\ServiceRepository;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Modules\AddonModuleRepository;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\View;
use Throwable;

/**
 * Client area: /client/services/{id}/cloudflare — the service owner only, on the
 * main site and on every reseller store (white-labelled: nothing here names the
 * platform). 404 while the add-on is inactive.
 *
 * Every POST redirects back (PRG) with a flash message.
 */
final class CloudflareClientController
{
    public const TABS = ['overview', 'dns', 'ssl', 'caching', 'security', 'activity'];

    private const FLASH_OK = 'cf_notice';
    private const FLASH_ERR = 'cf_error';

    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly ServiceRepository $services,
        private readonly AddonModuleRepository $addons,
        private readonly SessionManager $session,
        private readonly CloudflareService $cloudflare,
        private readonly CloudflareZoneRepository $zones
    ) {
    }

    public function show(Request $request, array $params): Response
    {
        [$service, $denied] = $this->owned((int) $params['id']);

        if ($denied !== null) {
            return $denied;
        }

        $state = $this->cloudflare->stateFor($service);

        if (!$state['show']) {
            return Response::html('404 Not Found', 404);
        }

        $zone = $state['zone'];
        $tab = (string) $request->query('tab', 'overview');
        $tab = in_array($tab, self::TABS, true) && $zone !== null ? $tab : 'overview';
        $data = [
            'service' => $service,
            'zone' => $zone,
            'state' => $state,
            'tab' => $tab,
            'notice' => $this->session->pullFlash(self::FLASH_OK),
            'error' => $this->session->pullFlash(self::FLASH_ERR),
            'registered' => $zone !== null && $this->cloudflare->registeredDomain((string) $zone['name'], (int) $service['client_id']) !== null,
            'manageable' => $zone !== null && $zone['delete_after'] === null && (string) $service['status'] === 'active',
            'records' => [],
            'settings' => [],
            'rules' => [],
            'activity' => [],
            'loadError' => null,
            'editId' => (string) $request->query('edit', ''),
        ];

        if ($zone !== null) {
            $load = match ($tab) {
                'dns' => $this->cloudflare->dnsRecords($zone),
                'ssl', 'caching' => $this->cloudflare->zoneSettings($zone),
                'security' => $this->securityData($zone),
                default => ['ok' => true, 'message' => 'OK'],
            };

            $data['records'] = $load['records'] ?? [];
            $data['settings'] = $load['settings'] ?? [];
            $data['rules'] = $load['rules'] ?? [];
            $data['loadError'] = $load['ok'] ? null : $load['message'];

            if ($tab === 'activity' || $tab === 'overview') {
                $data['activity'] = $this->zones->activity((int) $zone['id'], $tab === 'overview' ? 5 : 100);
            }
        }

        return Response::html($this->view->render('layouts.client', [
            'title' => 'Cloudflare — ' . (string) ($zone['name'] ?? $service['domain'] ?? $service['product_name']),
            'content' => $this->view->render('cloudflare.client', $data),
        ]));
    }

    public function enable(Request $request, array $params): Response
    {
        [$service, $denied] = $this->owned((int) $params['id']);

        if ($denied !== null) {
            return $denied;
        }

        $state = $this->cloudflare->stateFor($service);

        if (!$state['canEnable']) {
            return $this->back($service, 'overview', ['ok' => false, 'message' => $state['reason'] ?: 'Cloudflare is not available for this service.']);
        }

        return $this->back($service, 'overview', $this->cloudflare->enable((int) $service['id'], $this->actor()));
    }

    public function check(Request $request, array $params): Response
    {
        return $this->withZone($params, false, fn (array $zone): array => $this->cloudflare->checkActivation((int) $zone['id'], $this->actor()));
    }

    public function nameservers(Request $request, array $params): Response
    {
        return $this->withZone($params, true, fn (array $zone): array => $this->cloudflare->switchNameservers((int) $zone['id'], $this->actor()));
    }

    public function disable(Request $request, array $params): Response
    {
        if ((string) $request->input('confirm', '') !== '1') {
            [$service, $denied] = $this->owned((int) $params['id']);

            return $denied ?? $this->back($service, 'overview', ['ok' => false, 'message' => 'Tick the box to confirm turning Cloudflare off.']);
        }

        return $this->withZone($params, false, function (array $zone): array {
            if ($zone['delete_after'] !== null) {
                return ['ok' => true, 'message' => 'Removal is already scheduled.'];
            }

            return $this->cloudflare->scheduleDeletion((int) $zone['id'], 'client', $this->actor());
        });
    }

    public function keep(Request $request, array $params): Response
    {
        return $this->withZone($params, false, fn (array $zone): array => $this->cloudflare->cancelDeletion((int) $zone['id'], $this->actor()));
    }

    public function saveDns(Request $request, array $params): Response
    {
        $recordId = isset($params['rid']) ? (string) $params['rid'] : null;

        return $this->withZone($params, true, fn (array $zone): array => $this->cloudflare->saveDns($zone, [
            'type' => $request->input('type', ''),
            'name' => $request->input('name', ''),
            'content' => $request->input('content', ''),
            'ttl' => $request->input('ttl', 1),
            'proxied' => $request->input('proxied', '0'),
            'priority' => $request->input('priority', 10),
        ], $recordId, $this->actor()), 'dns');
    }

    public function deleteDns(Request $request, array $params): Response
    {
        return $this->withZone($params, true, fn (array $zone): array => $this->cloudflare->deleteDns($zone, (string) $params['rid'], $this->actor()), 'dns');
    }

    public function setting(Request $request, array $params): Response
    {
        $setting = (string) $request->input('setting', '');
        $tab = in_array($setting, ['security_level', 'browser_check'], true) ? 'security'
            : (in_array($setting, ['development_mode', 'cache_level', 'browser_cache_ttl'], true) ? 'caching' : 'ssl');

        return $this->withZone($params, true, fn (array $zone): array => $this->cloudflare->changeSetting($zone, $setting, (string) $request->input('value', ''), $this->actor()), $tab);
    }

    public function purge(Request $request, array $params): Response
    {
        $everything = (string) $request->input('everything', '') === '1';
        $urls = $everything ? null : (preg_split('/\R/', (string) $request->input('urls', '')) ?: []);

        return $this->withZone($params, true, fn (array $zone): array => $this->cloudflare->purge($zone, $urls, $this->actor()), 'caching');
    }

    public function addRule(Request $request, array $params): Response
    {
        return $this->withZone($params, true, fn (array $zone): array => $this->cloudflare->addAccessRule(
            $zone,
            (string) $request->input('mode', ''),
            (string) $request->input('target', ''),
            (string) $request->input('value', ''),
            (string) $request->input('notes', ''),
            $this->actor()
        ), 'security');
    }

    public function deleteRule(Request $request, array $params): Response
    {
        return $this->withZone($params, true, fn (array $zone): array => $this->cloudflare->deleteAccessRule($zone, (string) $params['rule'], $this->actor()), 'security');
    }

    /**
     * @param array<string, string> $params
     * @param callable(array<string, mixed>): array{ok: bool, message: string} $action
     */
    private function withZone(array $params, bool $mustBeManageable, callable $action, string $tab = 'overview'): Response
    {
        [$service, $denied] = $this->owned((int) $params['id']);

        if ($denied !== null) {
            return $denied;
        }

        $zone = $this->zones->liveForService((int) $service['id']);

        if ($zone === null) {
            return $this->back($service, 'overview', ['ok' => false, 'message' => 'Cloudflare is not set up for this service.']);
        }

        if ($mustBeManageable && $zone['delete_after'] !== null) {
            return $this->back($service, 'overview', ['ok' => false, 'message' => 'Cloudflare is scheduled for removal. Choose "Keep Cloudflare" first to make changes.']);
        }

        if ($mustBeManageable && (string) $service['status'] !== 'active') {
            return $this->back($service, 'overview', ['ok' => false, 'message' => 'Cloudflare settings can be changed while the service is active.']);
        }

        try {
            $result = $action($zone);
        } catch (Throwable) {
            $result = ['ok' => false, 'message' => 'Something went wrong. Please try again.'];
        }

        return $this->back($service, $tab, $result);
    }

    /**
     * @param array<string, mixed> $service
     * @param array{ok: bool, message: string} $result
     */
    private function back(array $service, string $tab, array $result): Response
    {
        $this->session->flash($result['ok'] ? self::FLASH_OK : self::FLASH_ERR, $result['message']);

        return Response::redirect('/client/services/' . (int) $service['id'] . '/cloudflare' . ($tab !== 'overview' ? '?tab=' . $tab : ''));
    }

    /**
     * @param array<string, mixed> $zone
     * @return array{ok: bool, message: string, settings?: array<string, mixed>, rules?: array<int, array<string, mixed>>}
     */
    private function securityData(array $zone): array
    {
        $settings = $this->cloudflare->zoneSettings($zone);

        if (!$settings['ok']) {
            return $settings;
        }

        $rules = $this->cloudflare->accessRules($zone);

        return ['ok' => $rules['ok'], 'message' => $rules['message'], 'settings' => $settings['settings'] ?? [], 'rules' => $rules['rules'] ?? []];
    }

    /** @return array{type: string, id: ?int} */
    private function actor(): array
    {
        $client = $this->guard->currentClient();

        return ['type' => 'client', 'id' => $client !== null ? (int) $client['id'] : null];
    }

    /** @return array{0: array<string, mixed>|null, 1: Response|null} */
    private function owned(int $serviceId): array
    {
        try {
            if (!$this->addons->isActive(CloudflareCronJob::SLUG)) {
                return [null, Response::html('404 Not Found', 404)];
            }
        } catch (Throwable) {
            return [null, Response::html('404 Not Found', 404)];
        }

        $client = $this->guard->currentClient();

        if ($client === null) {
            return [null, Response::redirect('/client/login')];
        }

        $service = $this->services->find($serviceId);

        if ($service === null || (int) $service['client_id'] !== (int) $client['id']) {
            return [null, Response::html('404 Not Found', 404)];
        }

        return [$service, null];
    }
}
