<?php

declare(strict_types=1);

namespace CodeVault\Cloudflare;

use CodeVault\Auth\AuthGuard;
use CodeVault\Database;
use CodeVault\Modules\AddonModuleRepository;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;
use Throwable;

/**
 * /admin/cloudflare — dashboard (zones), zone detail, settings. Needs the
 * addons.manage permission; redirects to the add-on page while inactive.
 */
final class CloudflareAdminController
{
    private const ZONE_ACTIONS = ['sync', 'check', 'pause', 'resume', 'schedule', 'cancel', 'delete'];

    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly SessionManager $session,
        private readonly AddonModuleRepository $addons,
        private readonly CloudflareSettings $settings,
        private readonly CloudflareZoneRepository $zones,
        private readonly CloudflareService $cloudflare,
        private readonly Database $db
    ) {
    }

    public function index(Request $request): Response
    {
        if ($deny = $this->gate()) {
            return $deny;
        }

        $status = (string) $request->query('status', 'live');
        $status = in_array($status, ['live', 'active', 'pending', 'paused', 'deleting', 'deleted', 'all'], true) ? $status : 'live';
        $q = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));

        return $this->page('Cloudflare', 'cloudflare.admin-index', [
            'counts' => $this->zones->counts(),
            'rows' => $this->zones->search($status === 'all' ? null : $status, $q, 51, ($page - 1) * 50),
            'status' => $status,
            'q' => $q,
            'pageNo' => $page,
            'connected' => $this->settings->connected(),
            'accountName' => $this->settings->accountName(),
            'productCount' => count((new CloudflareProductOption($this->db, $this->settings))->attachedProductIds()),
        ]);
    }

    public function zone(Request $request, array $params): Response
    {
        if ($deny = $this->gate()) {
            return $deny;
        }

        $zone = $this->zones->detailed((int) $params['id']);

        if ($zone === null) {
            return Response::html('404 Not Found', 404);
        }

        return $this->page('Cloudflare — ' . $zone['name'], 'cloudflare.admin-zone', [
            'zone' => $zone,
            'activity' => $this->zones->activity((int) $zone['id'], 200),
            'graceDays' => $this->settings->graceDays(),
        ]);
    }

    public function zoneAction(Request $request, array $params): Response
    {
        if ($deny = $this->gate()) {
            return $deny;
        }

        $id = (int) $params['id'];
        $action = (string) $params['action'];

        if (!in_array($action, self::ZONE_ACTIONS, true)) {
            return Response::html('404 Not Found', 404);
        }

        $actor = $this->actor();

        try {
            $result = match ($action) {
                'sync' => $this->cloudflare->reconcile($id),
                'check' => $this->cloudflare->checkActivation($id, $actor),
                'pause' => $this->cloudflare->setPaused($id, true, false, $actor),
                'resume' => $this->cloudflare->setPaused($id, false, false, $actor),
                'schedule' => $this->cloudflare->scheduleDeletion($id, 'admin', $actor),
                'cancel' => $this->cloudflare->cancelDeletion($id, $actor),
                'delete' => (string) $request->input('confirm', '') === 'DELETE'
                    ? $this->cloudflare->deleteNow($id, $actor)
                    : ['ok' => false, 'message' => 'Type DELETE to confirm immediate deletion.'],
            };
        } catch (Throwable $e) {
            $result = ['ok' => false, 'message' => 'Failed: ' . $e->getMessage()];
        }

        $this->flash($result);

        return Response::redirect('/admin/cloudflare/zones/' . $id);
    }

    /** The BIND backup kept when a zone was deleted. */
    public function backup(Request $request, array $params): Response
    {
        if ($deny = $this->gate()) {
            return $deny;
        }

        $zone = $this->zones->find((int) $params['id']);

        if ($zone === null || (string) ($zone['backup_bind'] ?? '') === '') {
            return Response::html('404 Not Found', 404);
        }

        return (new Response((string) $zone['backup_bind'], 200))
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . preg_replace('/[^a-z0-9.-]/', '', (string) $zone['name']) . '.zone.txt"');
    }

    public function settingsPage(Request $request): Response
    {
        if ($deny = $this->gate()) {
            return $deny;
        }

        $option = new CloudflareProductOption($this->db, $this->settings);

        return $this->page('Cloudflare Settings', 'cloudflare.admin-settings', [
            'settings' => $this->settings,
            'hasToken' => $this->settings->token() !== '',
            'accounts' => $this->settings->accountsCache(),
            'products' => $option->products(),
            'attached' => $option->attachedProductIds(),
        ]);
    }

    public function saveSettings(Request $request): Response
    {
        if ($deny = $this->gate()) {
            return $deny;
        }

        $ssl = (string) $request->input('default_ssl', 'full');
        $level = (string) $request->input('default_security_level', 'medium');
        $this->settings->save([
            'default_ssl' => in_array($ssl, CloudflareSettings::SSL_MODES, true) ? $ssl : 'full',
            'default_always_https' => $request->input('default_always_https') ? '1' : '0',
            'default_security_level' => in_array($level, CloudflareSettings::SECURITY_LEVELS, true) ? $level : 'medium',
            'grace_days' => (string) max(1, min(90, (int) $request->input('grace_days', 7))),
            'allow_later' => $request->input('allow_later') ? '1' : '0',
        ]);

        $this->flash(['ok' => true, 'message' => 'Settings saved.']);

        return Response::redirect('/admin/cloudflare/settings');
    }

    /** Saves (optionally) a new token, verifies it and loads the accounts it can see. */
    public function connect(Request $request): Response
    {
        if ($deny = $this->gate()) {
            return $deny;
        }

        $token = trim((string) $request->input('api_token', ''));
        $accountId = trim((string) $request->input('account_id', ''));

        if ($token === '' && $this->settings->token() === '') {
            $this->flash(['ok' => false, 'message' => 'Paste a Cloudflare API token first.']);

            return Response::redirect('/admin/cloudflare/settings');
        }

        $api = $this->settings->api($token !== '' ? $token : null);

        try {
            $verify = $api->verifyToken();

            if (($verify['status'] ?? '') !== 'active') {
                throw new CloudflareApiException('The token is ' . (string) ($verify['status'] ?? 'not active') . '.');
            }

            $accounts = [];

            foreach ($api->accounts() as $account) {
                if (isset($account['id'], $account['name'])) {
                    $accounts[] = ['id' => (string) $account['id'], 'name' => (string) $account['name']];
                }
            }
        } catch (CloudflareApiException $e) {
            $this->flash(['ok' => false, 'message' => 'Verification failed: ' . $e->getMessage() . ($token !== '' ? ' The token was not saved.' : '')]);

            return Response::redirect('/admin/cloudflare/settings');
        }

        if ($accounts === []) {
            $this->flash(['ok' => false, 'message' => 'The token works but cannot see any account. Give it the "Account Settings: Read" permission (and Zone permissions) for your account.']);

            return Response::redirect('/admin/cloudflare/settings');
        }

        if ($token !== '') {
            $this->settings->saveToken($token);
        }

        $chosen = null;

        foreach ($accounts as $account) {
            if ($account['id'] === ($accountId !== '' ? $accountId : $this->settings->accountId())) {
                $chosen = $account;
            }
        }

        if ($chosen === null && count($accounts) === 1) {
            $chosen = $accounts[0];
        }

        $this->settings->save([
            'accounts_cache' => json_encode($accounts),
            'account_id' => $chosen['id'] ?? '',
            'account_name' => $chosen['name'] ?? '',
        ]);

        $this->flash($chosen !== null
            ? ['ok' => true, 'message' => 'Connected to Cloudflare account "' . $chosen['name'] . '".']
            : ['ok' => true, 'message' => 'Token verified. Choose which account new zones go into, then click Verify & save again.']);

        return Response::redirect('/admin/cloudflare/settings');
    }

    public function disconnect(Request $request): Response
    {
        if ($deny = $this->gate()) {
            return $deny;
        }

        $this->settings->saveToken('');
        $this->settings->save(['account_id' => '', 'account_name' => '', 'accounts_cache' => '[]']);
        $this->flash(['ok' => true, 'message' => 'Disconnected. Existing zones keep working in Cloudflare; WHMP stops managing them until you reconnect.']);

        return Response::redirect('/admin/cloudflare/settings');
    }

    public function saveProducts(Request $request): Response
    {
        if ($deny = $this->gate()) {
            return $deny;
        }

        $ids = $request->input('products', []);

        try {
            (new CloudflareProductOption($this->db, $this->settings))->syncProducts(is_array($ids) ? array_map('intval', $ids) : []);
            $this->flash(['ok' => true, 'message' => 'Products updated. Those products now show the free "Cloudflare CDN & Security" option at order.']);
        } catch (Throwable $e) {
            $this->flash(['ok' => false, 'message' => 'Could not update products: ' . $e->getMessage()]);
        }

        return Response::redirect('/admin/cloudflare/settings#products');
    }

    private function gate(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::ADDONS_MANAGE)) {
            return Response::html('403 Forbidden — missing addons.manage permission', 403);
        }

        if (!$this->addons->isActive(CloudflareCronJob::SLUG)) {
            return Response::redirect('/admin/addons/' . CloudflareCronJob::SLUG);
        }

        return null;
    }

    /** @return array{type: string, id: ?int} */
    private function actor(): array
    {
        $admin = $this->guard->currentAdmin();

        return ['type' => 'admin', 'id' => $admin !== null ? (int) $admin['id'] : null];
    }

    /** @param array{ok: bool, message: string} $result */
    private function flash(array $result): void
    {
        $this->session->flash($result['ok'] ? 'cf_admin_notice' : 'cf_admin_error', $result['message']);
    }

    /** @param array<string, mixed> $data */
    private function page(string $title, string $template, array $data): Response
    {
        $data += [
            'notice' => $this->session->pullFlash('cf_admin_notice'),
            'error' => $this->session->pullFlash('cf_admin_error'),
        ];

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — ' . $title,
            'content' => $this->view->render($template, $data),
        ]));
    }
}
