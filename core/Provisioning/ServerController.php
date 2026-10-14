<?php

declare(strict_types=1);

namespace CodeVault\Provisioning;

use CodeVault\Auth\AuthGuard;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\ProvisioningModule;
use CodeVault\Request;
use CodeVault\Security\SecretBox;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class ServerController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly ServerRepository $servers,
        private readonly ServerGroupRepository $groups,
        private readonly ModuleManager $modules,
        // Encrypts the provider account password. Optional and trailing like the
        // other late additions; without it, the field is simply not saved.
        private readonly ?SecretBox $secrets = null
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $page = max(1, (int) $request->query('page', 1));

        $filters = \CodeVault\Table\TableFilters::fromQuery(
            is_array($request->query()) ? $request->query() : [],
            ['name' => true, 'hostname' => true, 'module' => true, 'group' => true, 'status' => true]
        );

        // The status column is a boolean (active 0/1) — the repo maps the
        // 'active'/'disabled' filter values to the stored flag.
        $sort = \CodeVault\Table\TableFilters::sortFromQuery(
            is_array($request->query()) ? $request->query() : [],
            ['name' => 's.name', 'hostname' => 's.hostname', 'module' => 's.module_slug', 'group' => 'g.name', 'status' => 's.active']
        );

        $results = $this->servers->paginate($page, 20, $filters, $sort);

        return $this->render('provisioning.servers-index', [
            'results' => $results,
            'servers' => $results['data'],
            'filters' => $filters,
            'sort' => $sort,
            'filterColumns' => [
                ['filterable' => true, 'key' => 'name', 'label' => 'Name', 'type' => 'text', 'placeholder' => 'Server name'],
                ['filterable' => true, 'key' => 'hostname', 'label' => 'Hostname', 'type' => 'text', 'placeholder' => 'Hostname'],
                ['filterable' => true, 'key' => 'module', 'label' => 'Module', 'type' => 'text', 'placeholder' => 'e.g. cpanel'],
                ['filterable' => true, 'key' => 'group', 'label' => 'Group', 'type' => 'text', 'placeholder' => 'Group name'],
                ['filterable' => true, 'key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => [
                    'active' => 'Active',
                    'disabled' => 'Disabled',
                ]],
                ['filterable' => false],
            ],
            'groups' => $this->groups->all(),
            'moduleSlugs' => array_keys($this->modules->allOfType(ProvisioningModule::class)),
        ]);
    }

    public function storeGroup(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $name = trim((string) $request->input('name', ''));

        if ($name !== '') {
            $this->groups->create($name);
        }

        return Response::redirect('/admin/servers');
    }

    public function updateGroup(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $name = trim((string) $request->input('name', ''));

        if ($name !== '') {
            $this->groups->update((int) $params['id'], $name);
        }

        return Response::redirect('/admin/servers');
    }

    public function destroyGroup(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->groups->delete((int) $params['id']);

        return Response::redirect('/admin/servers');
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = $this->servers->create($this->extractFields($request));
        $this->saveAccountSecret($request, $id);

        return Response::redirect('/admin/servers');
    }

    public function editForm(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $server = $this->servers->find((int) $params['id']);

        if ($server === null) {
            return Response::html('404 Not Found', 404);
        }

        return $this->render('provisioning.server-edit', [
            'server' => $server,
            'groups' => $this->groups->all(),
            'moduleSlugs' => array_keys($this->modules->allOfType(ProvisioningModule::class)),
        ]);
    }

    public function update(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $fields = $this->extractFields($request);

        // A blank API token on re-save means "leave it as is" — an admin
        // editing an unrelated field (e.g. hostname) shouldn't have to
        // re-paste a secret token just to save the form.
        if ($fields['api_token'] === null) {
            $existing = $this->servers->find($id);
            $fields['api_token'] = $existing['api_token'] ?? null;
        }

        $this->servers->update($id, $fields);
        $this->saveAccountSecret($request, $id);

        return Response::redirect("/admin/servers/{$id}/edit");
    }

    public function toggle(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $server = $this->servers->find($id);

        if ($server !== null) {
            $this->servers->update($id, [
                'server_group_id' => $server['server_group_id'],
                'name' => $server['name'],
                'hostname' => $server['hostname'],
                'module_slug' => $server['module_slug'],
                'api_username' => $server['api_username'],
                'api_token' => $server['api_token'],
                'api_port' => $server['api_port'],
                'use_ssl' => $server['use_ssl'],
                'active' => $server['active'] ? 0 : 1,
            ]);
        }

        return Response::redirect('/admin/servers');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->servers->delete((int) $params['id']);

        return Response::redirect('/admin/servers');
    }

    public function testConnection(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $server = $this->servers->find((int) $params['id']);

        if ($server === null) {
            return Response::json(['success' => false, 'message' => 'Server not found.'], 404);
        }

        $module = $this->modules->get(ProvisioningModule::class, (string) $server['module_slug']);

        if ($module === null) {
            return Response::json([
                'success' => false,
                'message' => "No provisioning module registered for [{$server['module_slug']}].",
            ]);
        }

        // Record the raw exchange so the admin can see exactly what the provider
        // answered (status, headers, body, IPs), with the server's secrets hidden.
        $secrets = [$server['api_token'] ?? null, $server['account_secret'] ?? null];

        if ($this->secrets !== null && !empty($server['account_secret'])) {
            $secrets[] = $this->secrets->decrypt((string) $server['account_secret']);
        }

        HttpExchangeLog::start();

        try {
            $result = $module->testConnection(['server' => $server]);
        } catch (\Throwable $e) {
            $result = ['success' => false, 'message' => 'Connection test failed: ' . $e->getMessage()];
        } finally {
            $exchanges = HttpExchangeLog::stop($secrets);
        }

        return Response::json([
            'success' => (bool) ($result['success'] ?? false),
            'message' => (string) ($result['message'] ?? ($result['success'] ?? false ? 'Connected.' : 'Connection failed.')),
            'exchanges' => $exchanges,
            'testedAt' => gmdate('Y-m-d H:i:s') . ' UTC',
        ])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * The provider ACCOUNT password (InterServer: re-checked on OS reinstall and
     * backup restore), stored encrypted. Blank means "keep what is saved", the same
     * as the API token. The separate "clear" box removes it.
     */
    private function saveAccountSecret(Request $request, int $id): void
    {
        if ($request->input('clear_account_secret')) {
            $this->servers->setAccountSecret($id, null);

            return;
        }

        $plain = (string) $request->input('account_secret', '');

        if (trim($plain) === '' || $this->secrets === null || !$this->secrets->available()) {
            return;
        }

        $this->servers->setAccountSecret($id, $this->secrets->encrypt($plain));
    }

    /** @return array<string, mixed> */
    private function extractFields(Request $request): array
    {
        $groupId = $request->input('server_group_id');
        $port = trim((string) $request->input('api_port', ''));
        $slug = (string) $request->input('module_slug', 'local');
        $token = trim((string) $request->input('api_token', ''));

        // InterServer keys are long single strings. One copied from an email or a
        // wrapped page can pick up line breaks or spaces, which make it invalid.
        if (str_contains($slug, 'interserver')) {
            $token = (string) preg_replace('/\s+/', '', $token);
        }

        return [
            'server_group_id' => $groupId !== null && $groupId !== '' ? (int) $groupId : null,
            'name' => trim((string) $request->input('name', '')),
            'hostname' => trim((string) $request->input('hostname', '')),
            'module_slug' => $slug,
            'api_username' => trim((string) $request->input('api_username', '')) ?: null,
            'api_token' => $token !== '' ? $token : null,
            'api_port' => $port === '' ? null : (int) $port,
            'use_ssl' => $request->input('use_ssl') ? 1 : 0,
            'active' => $request->input('active') ? 1 : 0,
        ];
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::SERVERS_MANAGE)) {
            return Response::html('403 Forbidden — missing servers.manage permission', 403);
        }

        return null;
    }

    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Servers',
            'content' => $content,
        ]));
    }
}
