<?php

declare(strict_types=1);

namespace CodeVault\UsernameChanger;

use CodeVault\Auth\AuthGuard;
use CodeVault\Database;
use CodeVault\Modules\AddonModuleRepository;
use CodeVault\Provisioning\ProvisioningService;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;
use DateTimeImmutable;

/**
 * Super-admin screens (Addons → cPanel Username Changer): dashboard + queue,
 * request detail with inline actions, the manual change tool, settings
 * (including the payment switch), product and client policies, server state,
 * and the audit log. Gated by `addons.manage` and by the add-on being active.
 */
final class AdminUsernameController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly SessionManager $session,
        private readonly AddonModuleRepository $addons,
        private readonly UsernameChangeRepository $requests,
        private readonly UsernameChangeService $service,
        private readonly UsernameAvailability $availability,
        private readonly UsernameChangerSettings $settings,
        private readonly PolicyResolver $policies,
        private readonly Database $db,
        private readonly ?ProvisioningService $provisioning = null,
        private readonly ?\CodeVault\Billing\CurrencyRepository $currencies = null
    ) {
    }

    public function index(Request $request): Response
    {
        if ($deny = $this->gate()) {
            return $deny;
        }

        $status = (string) $request->query('status', 'open');
        $q = (string) $request->query('q', '');
        $page = max(1, (int) $request->query('page', 1));
        $since = (new DateTimeImmutable('-7 days'))->format('Y-m-d H:i:s');
        $today = (new DateTimeImmutable('today'))->format('Y-m-d H:i:s');

        return $this->page('Username Changer', 'username-changer.admin-index', [
            'counts' => $this->requests->countsByStatus(),
            'stats' => [
                'today' => $this->requests->countSince($today),
                'week' => $this->requests->countSince($since),
                'completedWeek' => $this->requests->countSince($since, 'completed'),
                'mismatch' => $this->requests->mismatchCount(),
            ],
            'rows' => $this->requests->search($status === 'all' ? null : $status, $q, null, false, 50, ($page - 1) * 50),
            'status' => $status,
            'q' => $q,
            'page' => $page,
            'feeEnabled' => $this->settings->feeEnabled(),
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        if ($deny = $this->gate()) {
            return $deny;
        }

        $r = $this->requests->findDetailed((int) $params['id']);

        if ($r === null) {
            return Response::html('404 Not Found', 404);
        }

        $store = $r['reseller_id'] === null ? null : $this->db->selectOne('SELECT id, client_id, slug, brand_name FROM resellers WHERE id = ?', [(int) $r['reseller_id']]);

        return $this->page('Username change #' . (int) $r['id'], 'username-changer.admin-request', [
            'r' => $r,
            'store' => $store,
            'events' => $this->requests->events((int) $r['id']),
        ]);
    }

    public function action(Request $request, array $params): Response
    {
        if ($deny = $this->gate()) {
            return $deny;
        }

        $id = (int) $params['id'];
        $adminId = (int) ($this->guard->currentAdmin()['id'] ?? 0);
        $ip = $request->ip();

        $result = match ((string) $params['action']) {
            'approve' => $this->service->approve($id, 'admin', $adminId, $ip),
            'decline' => $this->service->decline($id, (string) $request->input('reason', ''), 'admin', $adminId, $ip),
            'cancel' => $this->service->cancel($id, 'admin', $adminId, $ip),
            'retry' => $this->service->retry($id, $adminId, $ip),
            'waive' => $this->service->waivePayment($id, $adminId, $ip),
            'sync' => $this->syncFromServer($id, $adminId, $ip),
            default => ['ok' => false, 'message' => 'Unknown action.'],
        };

        $this->flash($result);
        $back = (string) $request->input('back', '');

        return Response::redirect(str_starts_with($back, '/admin/username-changer') ? $back : '/admin/username-changer/requests/' . $id);
    }

    public function manual(Request $request): Response
    {
        if ($deny = $this->gate()) {
            return $deny;
        }

        $serviceId = (int) ($request->input('service_id') ?? $request->query('service_id', 0));
        $service = $serviceId > 0 ? $this->db->selectOne(
            'SELECT s.id, s.username, s.domain, s.status, s.server_id, sv.name AS server_name, sv.module_slug, c.first_name, c.last_name, c.email
               FROM services s JOIN clients c ON c.id = s.client_id LEFT JOIN servers sv ON sv.id = s.server_id WHERE s.id = ?',
            [$serviceId]
        ) : null;
        $result = null;

        if ($request->method() === 'POST' && $service !== null) {
            $adminId = (int) ($this->guard->currentAdmin()['id'] ?? 0);
            $result = $this->service->adminRename(
                $serviceId,
                (string) $request->input('new_username', ''),
                $request->input('rename_db') === '1',
                $adminId,
                $request->ip(),
                $request->input('mode') === 'preflight'
            );

            if ($result['ok'] && isset($result['request_id'])) {
                $this->flash($result);

                return Response::redirect('/admin/username-changer/requests/' . (int) $result['request_id']);
            }
        }

        $matches = [];
        $search = trim((string) $request->query('q', ''));

        if ($search !== '' && $service === null) {
            $like = '%' . $search . '%';
            $matches = $this->db->select(
                "SELECT s.id, s.username, s.domain, s.status, c.first_name, c.last_name FROM services s JOIN clients c ON c.id = s.client_id
                   JOIN servers sv ON sv.id = s.server_id
                  WHERE sv.module_slug = 'cpanel' AND (s.username LIKE ? OR s.domain LIKE ? OR c.email LIKE ?) ORDER BY s.id DESC LIMIT 25",
                [$like, $like, $like]
            );
        }

        return $this->page('Manual username change', 'username-changer.admin-manual', [
            'service' => $service,
            'history' => $service === null ? [] : $this->requests->forService((int) $service['id']),
            'result' => $result,
            'search' => $search,
            'matches' => $matches,
            'rules' => $this->settings->policy()->toClientRules(),
            'input' => (string) $request->input('new_username', ''),
        ]);
    }

    /** GET /admin/username-changer/check?service_id=&u= — the manual tool's live check. */
    public function check(Request $request): Response
    {
        if ($deny = $this->gate()) {
            return Response::json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $service = $this->db->selectOne('SELECT id, server_id, username FROM services WHERE id = ?', [(int) $request->query('service_id', 0)]);

        if ($service === null) {
            return Response::json(['ok' => false, 'message' => 'Service not found.'], 404);
        }

        $name = UsernamePolicy::normalise((string) $request->query('u', ''));
        $result = $this->availability->fast($name, $service);

        if ($result['ok']) {
            // Free locally — confirm with WHM (the only authority).
            $this->session->release();
            $result = $this->availability->live($name, $service);
        }

        return Response::json($result + ['u' => $name])->withHeader('Cache-Control', $result['code'] === 'unverified' ? 'no-store' : 'private, max-age=10');
    }

    public function settingsPage(Request $request): Response
    {
        if ($deny = $this->gate()) {
            return $deny;
        }

        $products = $this->db->select(
            "SELECT p.id, p.name, p.type FROM products p WHERE p.type IN ('shared', 'reseller', 'vps', 'dedicated', 'other') ORDER BY p.type, p.name"
        );
        $productPolicies = [];

        foreach ($this->requests->policies('product') as $row) {
            $productPolicies[(int) $row['scope_id']] = $row;
        }

        $clientPolicies = $this->db->select(
            "SELECT p.*, c.first_name, c.last_name, c.email FROM username_change_policies p JOIN clients c ON c.id = p.scope_id WHERE p.scope = 'client' ORDER BY p.updated_at DESC"
        );

        $values = [];

        foreach (array_keys(UsernameChangerSettings::DEFAULTS) as $key) {
            $values[$key] = $this->settings->raw($key);
        }

        return $this->page('Username Changer settings', 'username-changer.admin-settings', [
            'values' => $values,
            'products' => $products,
            'productPolicies' => $productPolicies,
            'clientPolicies' => $clientPolicies,
            'servers' => $this->availability->serverStates(),
            'catalogCode' => (string) ($this->currencies?->pricing()['code'] ?? ''),
        ]);
    }

    public function saveSettings(Request $request): Response
    {
        if ($deny = $this->gate()) {
            return $deny;
        }

        $in = static fn (string $k, string $d = ''): string => trim((string) $request->input($k, $d));
        $list = static fn (string $k): string => implode(',', array_map('strval', (array) $request->input($k, [])));
        $fee = max(0.0, round((float) str_replace(',', '', $in('fee', '0')), 2));

        $this->settings->save([
            'min_length' => (string) max(1, min(16, (int) $in('min_length', '5'))),
            'max_length' => (string) max(1, min(16, (int) $in('max_length', '16'))),
            'reserved_extra' => mb_substr($in('reserved_extra'), 0, 2000),
            'unique_scope' => $in('unique_scope') === 'server' ? 'server' : 'platform',
            'max_changes' => (string) max(0, (int) $in('max_changes', '1')),
            'cooldown_days' => (string) max(0, (int) $in('cooldown_days', '30')),
            'statuses' => $list('statuses') ?: 'active',
            'product_types' => $list('product_types') ?: 'shared,reseller',
            'approval' => $in('approval') === 'admin' ? 'admin' : 'none',
            'confirm_methods' => $list('confirm_methods') ?: 'email',
            'confirm_ttl_hours' => (string) max(1, min(720, (int) $in('confirm_ttl_hours', '48'))),
            'execution' => $in('execution') === 'queued' ? 'queued' : 'immediate',
            'max_attempts' => (string) max(1, min(10, (int) $in('max_attempts', '3'))),
            'allow_db_rename' => $request->input('allow_db_rename') === '1' ? '1' : '0',
            'require_reason' => $request->input('require_reason') === '1' ? '1' : '0',
            'retention_days' => (string) max(7, (int) $in('retention_days', '365')),
            'stores_allowed' => $request->input('stores_allowed') === '1' ? '1' : '0',
            'store_approval_allowed' => $request->input('store_approval_allowed') === '1' ? '1' : '0',
            'staff_alert_email' => filter_var($in('staff_alert_email'), FILTER_VALIDATE_EMAIL) ? $in('staff_alert_email') : '',
            'first8_rule' => in_array($in('first8_rule'), ['on', 'off'], true) ? $in('first8_rule') : 'auto',
            'heading' => mb_substr($in('heading', 'Change cPanel username'), 0, 80),
            'accent' => preg_match('/^#[0-9a-fA-F]{6}$/', $in('accent')) ? $in('accent') : '',
            'fee_enabled' => $request->input('fee_enabled') === '1' ? '1' : '0',
            'fee' => number_format($fee, 2, '.', ''),
            'store_pricing' => $request->input('store_pricing') === '1' ? '1' : '0',
        ]);

        $this->flash(['ok' => true, 'message' => 'Settings saved.']);

        return Response::redirect('/admin/username-changer/settings');
    }

    public function saveProductPolicies(Request $request): Response
    {
        if ($deny = $this->gate()) {
            return $deny;
        }

        $rows = (array) $request->input('p', []);

        foreach ($rows as $productId => $fields) {
            if (!is_array($fields) || (int) $productId <= 0) {
                continue;
            }

            $clean = [
                'enabled' => self::nullableInt($fields['enabled'] ?? ''),
                'max_changes' => self::nullableInt($fields['max_changes'] ?? ''),
                'cooldown_days' => self::nullableInt($fields['cooldown_days'] ?? ''),
                'approval' => in_array($fields['approval'] ?? '', ['none', 'admin'], true) ? $fields['approval'] : null,
                'allow_db_rename' => self::nullableInt($fields['allow_db_rename'] ?? ''),
                'fee' => ($fields['fee'] ?? '') === '' ? null : max(0.0, round((float) $fields['fee'], 2)),
            ];

            if (array_filter($clean, static fn ($v) => $v !== null) === []) {
                $this->requests->deletePolicy('product', (int) $productId);
            } else {
                $this->requests->savePolicy('product', (int) $productId, $clean);
            }
        }

        $this->flash(['ok' => true, 'message' => 'Product policies saved.']);

        return Response::redirect('/admin/username-changer/settings#products');
    }

    public function saveClientPolicy(Request $request): Response
    {
        if ($deny = $this->gate()) {
            return $deny;
        }

        $clientId = (int) $request->input('client_id', 0);
        $client = $this->db->selectOne('SELECT id FROM clients WHERE id = ?', [$clientId]);

        if ($client === null) {
            $this->flash(['ok' => false, 'message' => 'No client with that ID.']);

            return Response::redirect('/admin/username-changer/settings#clients');
        }

        if ($request->input('remove') === '1') {
            $this->requests->deletePolicy('client', $clientId);
            $this->flash(['ok' => true, 'message' => 'Client override removed.']);

            return Response::redirect('/admin/username-changer/settings#clients');
        }

        $mode = (string) $request->input('client_mode', '');
        $this->requests->savePolicy('client', $clientId, [
            'client_mode' => in_array($mode, ['waive', 'block'], true) ? $mode : null,
            'extra_changes' => self::nullableInt($request->input('extra_changes', '')),
            'note' => mb_substr(trim((string) $request->input('note', '')), 0, 255) ?: null,
        ]);
        $this->flash(['ok' => true, 'message' => 'Client override saved.']);

        return Response::redirect('/admin/username-changer/settings#clients');
    }

    public function serverAction(Request $request, array $params): Response
    {
        if ($deny = $this->gate()) {
            return $deny;
        }

        $serverId = (int) $params['id'];

        if ((string) $params['action'] === 'refresh') {
            $n = $this->availability->refreshServer($serverId);
            $this->flash($n === null
                ? ['ok' => false, 'message' => 'The server did not answer — the previous account list is kept.']
                : ['ok' => true, 'message' => "Account list refreshed: {$n} accounts."]);
        } else {
            $engine = (string) $request->input('engine', '');
            $this->availability->setEngineOverride($serverId, $engine === '' ? null : $engine);
            $this->flash(['ok' => true, 'message' => 'Database engine setting saved.']);
        }

        return Response::redirect('/admin/username-changer/settings#servers');
    }

    public function audit(Request $request): Response
    {
        if ($deny = $this->gate()) {
            return $deny;
        }

        $q = (string) $request->query('q', '');

        return $this->page('Username Changer audit log', 'username-changer.admin-audit', [
            'events' => $this->requests->auditLog($q),
            'q' => $q,
        ]);
    }

    /** @return array{ok: bool, message: string} */
    private function syncFromServer(int $id, int $adminId, string $ip): array
    {
        $r = $this->requests->find($id);

        if ($r === null || $this->provisioning === null) {
            return ['ok' => false, 'message' => 'Request not found.'];
        }

        $exists = $this->provisioning->accountExists((int) $r['service_id'], (string) $r['new_username']);

        if (!$exists['known']) {
            return ['ok' => false, 'message' => 'Could not reach the server: ' . $exists['message']];
        }

        if (!$exists['exists']) {
            return ['ok' => false, 'message' => 'The server has no account named “' . $r['new_username'] . '” — nothing to sync.'];
        }

        $this->db->update('UPDATE services SET username = ?, updated_at = ? WHERE id = ?', [(string) $r['new_username'], UsernameChangeRepository::now(), (int) $r['service_id']]);
        $this->requests->patch($id, ['sync_state' => 'ok']);
        $this->requests->event($id, 'synced', 'admin', $adminId, $ip, 'Synced from server by staff.');

        return ['ok' => true, 'message' => 'The service record now matches the server.'];
    }

    private function gate(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::ADDONS_MANAGE)) {
            return Response::html('403 Forbidden — missing addons.manage permission', 403);
        }

        if (!$this->addons->isActive(UsernameChangeCronJob::SLUG)) {
            return Response::redirect('/admin/addons/' . UsernameChangeCronJob::SLUG);
        }

        return null;
    }

    /** @param array<string, mixed> $result */
    private function flash(array $result): void
    {
        $this->session->flash(($result['ok'] ?? false) ? 'ucn_notice' : 'ucn_error', (string) ($result['message'] ?? ''));
    }

    private static function nullableInt(mixed $v): ?int
    {
        return $v === '' || $v === null ? null : max(0, (int) $v);
    }

    private function page(string $title, string $template, array $data): Response
    {
        $data += [
            'notice' => $this->session->pullFlash('ucn_notice'),
            'error' => $this->session->pullFlash('ucn_error'),
            'pending' => $this->requests->pendingCount(),
        ];

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — ' . $title,
            'content' => $this->view->render($template, $data),
        ]));
    }
}
