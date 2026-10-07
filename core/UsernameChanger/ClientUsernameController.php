<?php

declare(strict_types=1);

namespace CodeVault\UsernameChanger;

use CodeVault\Billing\CurrencyService;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Database;
use CodeVault\Modules\AddonModuleRepository;
use CodeVault\Reseller\CurrentReseller;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\View;
use Throwable;

/**
 * Client endpoints (plan §7) — on the main site and on every store, for the
 * signed-in owner of the service only. While the add-on is off every route is a
 * plain 404, so nothing reveals the feature exists.
 *
 * The live check is built for speed: no database writes (its rate limit lives in
 * the session), indexed reads only, and a short private cache header so the
 * browser's own cache absorbs repeat look-ups.
 */
final class ClientUsernameController
{
    public const CHECKS_PER_MINUTE = 90;

    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly SessionManager $session,
        private readonly AddonModuleRepository $addons,
        private readonly UsernameChangeRepository $requests,
        private readonly PolicyResolver $policies,
        private readonly UsernameAvailability $availability,
        private readonly UsernameChangeService $service,
        private readonly UsernameChangerSettings $settings,
        private readonly Database $db,
        private readonly ?CurrentReseller $site = null,
        private readonly ?CurrencyService $currency = null
    ) {
    }

    /** GET /client/services/{id}/username — the modal's data. */
    public function show(Request $request, array $params): Response
    {
        [$service, $client, $deny] = $this->owned($params);

        if ($deny !== null) {
            return $deny;
        }

        $policy = $this->policies->forService((int) $service['id']);

        if (!($policy['eligible'] ?? false)) {
            return self::json(['ok' => false, 'message' => 'Not available for this service.'], 404);
        }

        return self::json(['ok' => true] + $this->modalData($policy, $client));
    }

    /** GET /client/services/{id}/username/check?u= — the live availability check. */
    public function check(Request $request, array $params): Response
    {
        [$service, $client, $deny] = $this->owned($params);

        if ($deny !== null) {
            return $deny;
        }

        if (!$this->allowCheck()) {
            return self::json(['ok' => false, 'code' => 'slow_down', 'message' => 'Slow down a little — try again in a moment.'], 429);
        }

        $name = UsernamePolicy::normalise((string) $request->query('u', ''));
        $ctx = ['id' => (int) $service['id'], 'server_id' => $service['server_id'], 'username' => (string) $service['username']];
        $result = $this->availability->fast($name, $ctx);
        $out = ['ok' => $result['ok'], 'code' => $result['code'], 'message' => $result['message'], 'u' => $name];

        if (!$result['ok'] && in_array($result['code'], ['taken', 'pending', 'prefix8', 'reserved', 'test_prefix', 'same', 'server'], true)) {
            $out['suggestions'] = $this->availability->suggest($name, $ctx, [
                'domain' => (string) ($service['domain'] ?? ''),
                'first_name' => (string) ($client['first_name'] ?? ''),
                'last_name' => (string) ($client['last_name'] ?? ''),
            ]);
        }

        return self::json($out)->withHeader('Cache-Control', 'private, max-age=20');
    }

    /** POST /client/services/{id}/username — create the request. */
    public function submit(Request $request, array $params): Response
    {
        [$service, $client, $deny] = $this->owned($params);

        if ($deny !== null) {
            return $deny;
        }

        if (!$this->requests->hit('req:' . (int) $client['id'], 10, 3600)) {
            return $this->reply($request, (int) $service['id'], ['ok' => false, 'message' => 'Too many requests — please try again later.'], 429);
        }

        $result = $this->service->request((int) $service['id'], (string) $request->input('new_username', ''), [
            'reason' => (string) $request->input('reason', ''),
            'rename_db' => $request->input('rename_db') === '1',
            'method' => (string) $request->input('method', 'email'),
            'pin' => (string) $request->input('pin', ''),
            'acknowledged' => $request->input('ack_login') === '1' && $request->input('ack_home') === '1' && $request->input('ack_ftp') === '1',
            'ip' => $request->ip(),
            'actor_type' => 'client',
            'actor_id' => (int) $client['id'],
        ]);

        return $this->reply($request, (int) $service['id'], $result, $result['ok'] ? 200 : 422);
    }

    /** POST /client/services/{id}/username/{rid}/cancel */
    public function cancel(Request $request, array $params): Response
    {
        [$service, $client, $deny] = $this->owned($params);

        if ($deny !== null) {
            return $deny;
        }

        $result = $this->ownRequest($service, (int) $params['rid'])
            ? $this->service->cancel((int) $params['rid'], 'client', (int) $client['id'], $request->ip(), (int) $client['id'])
            : ['ok' => false, 'message' => 'Request not found.'];

        return $this->reply($request, (int) $service['id'], $result);
    }

    /** POST /client/services/{id}/username/{rid}/resend */
    public function resend(Request $request, array $params): Response
    {
        [$service, $client, $deny] = $this->owned($params);

        if ($deny !== null) {
            return $deny;
        }

        $result = $this->ownRequest($service, (int) $params['rid'])
            ? $this->service->resend((int) $params['rid'], (int) $client['id'], $request->ip())
            : ['ok' => false, 'message' => 'Request not found.'];

        return $this->reply($request, (int) $service['id'], $result);
    }

    /**
     * GET /username-change/confirm/{token} — works signed out. Shows a Confirm
     * button only: link scanners and pre-fetchers issue GETs, so a GET must
     * never confirm anything.
     */
    public function confirmPage(Request $request, array $params): Response
    {
        if (!$this->active()) {
            return Response::html('404 Not Found', 404);
        }

        $token = (string) ($params['token'] ?? '');
        $r = $this->service->findByToken($token, $this->siteStoreId());
        $detail = $r === null ? null : $this->requests->findDetailed((int) $r['id']);

        return $this->page('username-changer.confirm', [
            'token' => $token,
            'request' => $detail,
            'expired' => $detail !== null && $detail['confirm_expires_at'] !== null && strtotime((string) $detail['confirm_expires_at']) < time(),
            'result' => null,
            'heading' => $this->settings->heading(),
        ]);
    }

    /** POST /username-change/confirm/{token} */
    public function confirm(Request $request, array $params): Response
    {
        if (!$this->active()) {
            return Response::html('404 Not Found', 404);
        }

        if (!$this->requests->hit('confirm:' . $request->ip(), 20, 3600)) {
            return Response::html('429 Too Many Requests', 429);
        }

        $token = (string) ($params['token'] ?? '');
        $result = $this->service->confirmByToken($token, $this->siteStoreId(), $request->ip());
        $detail = isset($result['request']) ? $this->requests->findDetailed((int) $result['request']['id']) : null;

        return $this->page('username-changer.confirm', [
            'token' => $token,
            'request' => $detail,
            'expired' => false,
            'result' => $result,
            'heading' => $this->settings->heading(),
        ]);
    }

    /**
     * What the service page needs to draw the banner — null when the banner
     * must not appear (add-on off, not cPanel, policy says no). Called by
     * ClientServiceController::show(); never throws.
     *
     * @param array<string, mixed> $client
     * @return array<string, mixed>|null
     */
    public function bannerFor(int $serviceId, array $client): ?array
    {
        try {
            if (!$this->active()) {
                return null;
            }

            $policy = $this->policies->forService($serviceId);

            if (!($policy['eligible'] ?? false) || (int) $policy['client_id'] !== (int) $client['id']) {
                return null;
            }

            return $this->modalData($policy, $client);
        } catch (Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    private function modalData(array $policy, array $client): array
    {
        $open = $this->requests->openForService((int) $policy['service_id']);
        $history = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'old' => (string) $r['old_username'],
            'new' => (string) $r['new_username'],
            'status' => (string) $r['status'],
            'label' => UsernameChangeNotifier::statusLabel((string) $r['status']),
            'created' => substr((string) $r['created_at'], 0, 16),
            'completed' => $r['completed_at'] === null ? null : substr((string) $r['completed_at'], 0, 16),
            'decline_reason' => $r['status'] === 'declined' ? (string) ($r['decline_reason'] ?? '') : null,
            'invoice_id' => $r['status'] === 'awaiting_payment' && $r['invoice_id'] !== null ? (int) $r['invoice_id'] : null,
        ], $this->requests->forService((int) $policy['service_id'], 10));

        $methods = $this->settings->confirmMethods();

        if (!$policy['has_pin']) {
            $methods = array_values(array_diff($methods, ['pin']));
        }

        return [
            'service_id' => (int) $policy['service_id'],
            'current' => (string) $policy['username'],
            'domain' => (string) $policy['domain'],
            'heading' => $this->settings->heading(),
            'accent' => $this->settings->accent(),
            'rules' => $this->settings->policy()->toClientRules(),
            'can_request' => (bool) $policy['can_request'] && $open === null,
            'blocked_reason' => $open !== null ? null : $policy['blocked_reason'],
            'remaining' => $policy['remaining'],
            'cooldown_days' => (int) $policy['cooldown_days'],
            'approval' => $policy['approval'] !== 'none',
            'allow_db_rename' => (bool) $policy['allow_db_rename'],
            'require_reason' => $this->settings->requireReason(),
            'methods' => $methods === [] ? ['email'] : $methods,
            'fee' => $this->feeLabel($policy, $client),
            'open' => $open === null ? null : [
                'id' => (int) $open['id'],
                'new' => (string) $open['new_username'],
                'status' => (string) $open['status'],
                'label' => UsernameChangeNotifier::statusLabel((string) $open['status']),
                'invoice_id' => $open['invoice_id'] === null ? null : (int) $open['invoice_id'],
                'can_cancel' => in_array($open['status'], ['awaiting_confirmation', 'pending_approval', 'awaiting_payment'], true),
                'can_resend' => $open['status'] === 'awaiting_confirmation' && $open['confirm_method'] === 'email',
            ],
            'history' => $history,
            'suggestions' => $this->availability->suggest('', ['id' => (int) $policy['service_id'], 'server_id' => $policy['server_id'], 'username' => $policy['username']], [
                'domain' => (string) $policy['domain'],
                'first_name' => (string) $policy['first_name'],
                'last_name' => (string) $policy['last_name'],
            ], 4),
        ];
    }

    /**
     * The fee in the client's own currency, e.g. "₦2,500.00", or null when free.
     *
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $client
     */
    private function feeLabel(array $policy, array $client): ?string
    {
        $pricing = $policy['pricing'] ?? [];

        if (!($pricing['charge'] ?? false) || $this->currency === null) {
            return null;
        }

        try {
            return $this->currency->format((float) $pricing['price'], $this->currency->resolveForClient($client));
        } catch (Throwable) {
            return number_format((float) $pricing['price'], 2);
        }
    }

    /** Session-based limiter: costs no database write per keystroke. */
    private function allowCheck(): bool
    {
        $now = time();
        $state = $this->session->get('ucn_check');
        $state = is_array($state) ? $state : ['t' => $now, 'n' => 0];

        if ($now - (int) $state['t'] >= 60) {
            $state = ['t' => $now, 'n' => 0];
        }

        $state['n'] = (int) $state['n'] + 1;
        $this->session->set('ucn_check', $state);

        return $state['n'] <= self::CHECKS_PER_MINUTE;
    }

    /** @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>, 2: ?Response} */
    private function owned(array $params): array
    {
        if (!$this->active()) {
            return [null, null, Response::html('404 Not Found', 404)];
        }

        $client = $this->guard->currentClient();

        if ($client === null) {
            return [null, null, self::json(['ok' => false, 'message' => 'Please sign in again.'], 401)];
        }

        $service = $this->db->selectOne('SELECT id, client_id, server_id, username, domain, status FROM services WHERE id = ?', [(int) ($params['id'] ?? 0)]);

        if ($service === null || (int) $service['client_id'] !== (int) $client['id']) {
            return [null, null, self::json(['ok' => false, 'message' => 'Not found.'], 404)];
        }

        return [$service, $client, null];
    }

    /** @param array<string, mixed> $service */
    private function ownRequest(array $service, int $requestId): bool
    {
        $r = $this->requests->find($requestId);

        return $r !== null && (int) $r['service_id'] === (int) $service['id'];
    }

    private function active(): bool
    {
        try {
            return $this->addons->isActive(UsernameChangeCronJob::SLUG);
        } catch (Throwable) {
            return false;
        }
    }

    private function siteStoreId(): ?int
    {
        try {
            return $this->site?->id();
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $result */
    private function reply(Request $request, int $serviceId, array $result, int $status = 200): Response
    {
        if ($request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest' || $request->input('ajax') === '1') {
            unset($result['request']);

            return self::json($result, $result['ok'] ?? false ? 200 : $status);
        }

        $key = ($result['ok'] ?? false) ? 'msg' : 'err';

        return Response::redirect("/client/services/{$serviceId}?{$key}=" . urlencode((string) $result['message']));
    }

    private static function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status)->withHeader('Cache-Control', 'no-store');
    }

    private function page(string $template, array $data): Response
    {
        return Response::html($this->view->render('layouts.client', [
            'title' => $this->settings->heading(),
            'content' => $this->view->render($template, $data),
        ]));
    }
}
