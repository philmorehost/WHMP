<?php

declare(strict_types=1);

namespace CodeVault\Provisioning;

use CodeVault\Billing\ServiceRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\ProvisioningModule;
use CodeVault\Security\SecretBox;

/**
 * The provisioning orchestration engine (blueprint §4.4): turns a Service
 * lifecycle transition into a call against whichever ProvisioningModule
 * the assigned server uses. A module failure never crashes the request —
 * it's recorded on the service (`provisioning_error`) so the admin can see
 * exactly what went wrong and retry, instead of the service getting stuck
 * with no explanation.
 */
final class ProvisioningService
{
    public function __construct(
        private readonly ServiceRepository $services,
        private readonly ProductRepository $products,
        private readonly ServerRepository $servers,
        private readonly ModuleManager $modules,
        private readonly HookDispatcher $hooks,
        // Decrypts servers.account_secret (a provider account password) for the
        // few calls that need it. Optional and trailing so hand-built instances keep
        // working; without it, no account password is ever passed to a module.
        private readonly ?SecretBox $secrets = null
    ) {
    }

    /**
     * @return array{success: bool, message: string, skipped?: bool}
     */
    public function provision(int $serviceId): array
    {
        $service = $this->services->find($serviceId);

        if ($service === null) {
            return ['success' => false, 'message' => 'Service not found.'];
        }

        $product = $this->products->find((int) $service['product_id']);

        if ($product === null || $product['server_group_id'] === null) {
            // Not every recurring product is provisioned onto a server
            // (e.g. a non-hosting subscription) — nothing to call, but the
            // service still needs to go active since nothing else gates it.
            $this->services->activate($serviceId);
            $this->hooks->fire(HookPoints::SERVICE_STATUS_CHANGED, ['serviceId' => $serviceId, 'status' => 'active']);

            return ['success' => true, 'message' => 'Product is not auto-provisioned.', 'skipped' => true];
        }

        $candidates = $this->servers->activeForGroupByLoad((int) $product['server_group_id']);

        if ($candidates === []) {
            $this->recordFailure($serviceId, 'No active server available in the assigned server group.');

            return ['success' => false, 'message' => 'No active server available in the assigned server group.'];
        }

        $server = $candidates[0];
        $module = $this->resolveModule($server['module_slug']);

        if ($module === null) {
            $this->recordFailure($serviceId, "Unknown provisioning module \"{$server['module_slug']}\".");

            return ['success' => false, 'message' => "Unknown provisioning module \"{$server['module_slug']}\"."];
        }

        $username = $service['username'] ?? $this->generateUsername($serviceId);

        $this->hooks->fire(HookPoints::BEFORE_MODULE_CREATE, ['serviceId' => $serviceId, 'server' => $server['name']]);

        $result = $module->create([
            'username' => $username,
            'product_name' => $service['product_name'],
            'whm_package_name' => $product['whm_package_name'] ?? null,
            'domain' => $service['domain'] ?? null,
            'password' => $service['password'] ?? null,
            'server' => $server,
        ]);

        if (!$result['success']) {
            $this->recordFailure($serviceId, $result['message']);

            return ['success' => false, 'message' => $result['message']];
        }

        $this->services->assignServer($serviceId, (int) $server['id'], $username);
        $this->services->activate($serviceId);
        $this->clearFailure($serviceId);

        $this->hooks->fire(HookPoints::AFTER_MODULE_CREATE, ['serviceId' => $serviceId, 'server' => $server['name']]);
        $this->hooks->fire(HookPoints::SERVICE_STATUS_CHANGED, ['serviceId' => $serviceId, 'status' => 'active']);

        return ['success' => true, 'message' => $result['message']];
    }

    public function suspend(int $serviceId, ?string $reason = null): array
    {
        return $this->transition(
            $serviceId,
            fn (ProvisioningModule $m, array $p) => $m->suspend($p),
            fn (int $id) => $this->services->suspend($id, $reason),
            HookPoints::AFTER_MODULE_SUSPEND,
            'suspended'
        );
    }

    public function unsuspend(int $serviceId): array
    {
        return $this->transition(
            $serviceId,
            fn (ProvisioningModule $m, array $p) => $m->unsuspend($p),
            fn (int $id) => $this->services->unsuspend($id),
            HookPoints::AFTER_MODULE_UNSUSPEND,
            'active'
        );
    }

    public function terminate(int $serviceId): array
    {
        return $this->transition(
            $serviceId,
            fn (ProvisioningModule $m, array $p) => $m->terminate($p),
            fn (int $id) => $this->services->terminate($id),
            HookPoints::AFTER_MODULE_TERMINATE,
            'terminated'
        );
    }

    /** @return array{success: bool, url?: string, message: string} */
    public function singleSignOn(int $serviceId): array
    {
        [$module, $params, $error] = $this->moduleAndParamsFor($serviceId);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        return $module->singleSignOn($params);
    }

    /**
     * $rootPassword is the NEW root password for the reinstalled machine. It always
     * overrides the service's stored password in the params: an empty one means "the
     * template's default", never "reuse the old one".
     */
    public function reinstall(int $serviceId, string $template, string $localPassword = '', string $rootPassword = ''): array
    {
        return $this->optional($serviceId, 'reinstall', [
            'template' => $template,
            'localPassword' => $localPassword,
            'password' => $rootPassword,
        ], 'OS reinstallation is not supported by this server module.');
    }

    /** Restore the machine from one of its own backups (`ref` from listBackups()). */
    public function restore(int $serviceId, string $backupRef): array
    {
        return $this->optional($serviceId, 'restore', ['backup' => $backupRef], 'Restoring a backup is not supported by this server module.');
    }

    /**
     * The out-of-band console. $clientIp is the address of the person asking; modules
     * that restrict console access by IP (InterServer) allow it first.
     *
     * @return array{success: bool, message: string, url?: ?string}
     */
    public function console(int $serviceId, string $clientIp): array
    {
        [$module, $params, $error] = $this->moduleAndParamsFor($serviceId);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        if (method_exists($module, 'console')) {
            return $module->console($params, $clientIp);
        }

        return $module->singleSignOn($params);
    }

    /**
     * How a service is tied to its machine on the provider account. For the admin's
     * service page. Null when the service's server module has no such notion.
     *
     * @return array{ref: ?string, via: string, linked: ?string}|null
     */
    public function remoteLink(int $serviceId): ?array
    {
        [$module, $params, $error] = $this->moduleAndParamsFor($serviceId);

        if ($error !== null || !$module instanceof LinksRemoteServices) {
            return null;
        }

        try {
            $resolved = $module->resolveRemote($params);
        } catch (\Throwable) {
            $resolved = ['ref' => null, 'via' => 'none'];
        }

        $linked = trim((string) ($params['remote_id'] ?? ''));

        return ['ref' => $resolved['ref'], 'via' => $resolved['via'], 'linked' => $linked !== '' ? $linked : null];
    }

    /**
     * Machines on the provider account behind a server, for the "link this service"
     * picker. Null when the server's module cannot list them.
     *
     * @return array{success: bool, message: string, services: array<int, array<string, mixed>>}|null
     */
    public function remoteServicesFor(int $serverId): ?array
    {
        $server = $this->servers->find($serverId);
        $module = $server === null ? null : $this->resolveModule((string) $server['module_slug']);

        if (!$module instanceof LinksRemoteServices) {
            return null;
        }

        try {
            return $module->remoteServices($this->withSecrets($server));
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Could not list the provider account: ' . $e->getMessage(), 'services' => []];
        }
    }

    /**
     * Read-only walk through everything the client's server buttons depend on, for
     * the admin's "self-service check". It answers "why did this button open a
     * ticket?" without anyone pressing a real button: nothing here reboots, wipes,
     * snapshots or changes the machine. Each step says what it checked, whether it
     * passed, the provider's own message, and which client buttons depend on it.
     *
     * `ok` is true (pass), false (fail: those buttons open tickets) or null (a
     * warning, or skipped because an earlier step failed).
     *
     * @return array{success: bool, message: string, steps: array<int, array{key: string, label: string, ok: ?bool, message: string, affects: string}>}
     */
    public function selfServiceCheck(int $serviceId): array
    {
        $steps = [];
        $add = static function (string $key, string $label, ?bool $ok, string $message, string $affects = '') use (&$steps): void {
            $steps[] = ['key' => $key, 'label' => $label, 'ok' => $ok, 'message' => $message, 'affects' => $affects];
        };
        $finish = static function (string $message) use (&$steps): array {
            $failed = array_filter($steps, static fn (array $s): bool => $s['ok'] === false);

            return ['success' => $failed === [], 'message' => $message, 'steps' => $steps];
        };
        $all = 'every server button';

        $service = $this->services->find($serviceId);

        if ($service === null) {
            $add('service', 'Service record', false, 'Service not found.', $all);

            return $finish('Service not found.');
        }

        $server = ($service['server_id'] ?? null) === null ? null : $this->servers->find((int) $service['server_id']);
        $module = $server === null ? null : $this->resolveModule((string) $server['module_slug']);

        if ($server === null || !$module instanceof LinksRemoteServices) {
            $why = $server === null
                ? 'No server is assigned to this service. Set "Assigned Server" (Edit Service Details) to your InterServer VPS or Nocix server record and save.'
                : "The assigned server \"{$server['name']}\" uses the \"{$server['module_slug']}\" module, which has no client self-service. Assign the InterServer VPS or Nocix server record instead.";
            $add('server', 'Assigned server', false, $why, $all);

            return $finish('Client self-service is off for this service: ' . $why);
        }

        $provider = $module instanceof NocixDedicatedServerModule ? 'Nocix' : 'InterServer';
        $machine = $provider === 'Nocix' ? 'dedicated server' : 'VPS';
        $add('server', 'Assigned server', true, "{$server['name']} ({$server['module_slug']})" . (empty($server['active']) && array_key_exists('active', $server) ? ' · this server record is disabled' : ''));

        $status = (string) ($service['status'] ?? '');
        $add(
            'status',
            'Service status',
            $status === 'active' ? true : null,
            $status === 'active'
                ? 'Active.'
                : "The service is \"{$status}\". The client only gets live server controls while it is active.",
            $status === 'active' ? '' : $all
        );

        [, $params] = $this->moduleAndParamsFor($serviceId);
        $params = $params !== [] ? $params : ['server' => $this->withSecrets($server)] + $service;

        try {
            $listing = $module->remoteServices($params['server']);
        } catch (\Throwable $e) {
            $listing = ['success' => false, 'message' => $e->getMessage(), 'services' => []];
        }

        if (!$listing['success']) {
            $add('account', "{$provider} API", false, (string) $listing['message'], $all);

            return $finish("WHMP could not read the {$provider} account, so every client server button opens a ticket.");
        }

        $count = count($listing['services']);
        $add('account', "{$provider} API", true, "Connected. {$count} {$machine}" . ($count === 1 ? '' : 's') . " on the {$provider} account.");

        try {
            $resolved = $module->resolveRemote($params);
        } catch (\Throwable $e) {
            $resolved = ['ref' => null, 'via' => 'none'];
        }

        if (($resolved['ref'] ?? null) === null) {
            $add('link', "Which {$machine} this service is", false, "No {$machine} on the {$provider} account is linked to this service or matches its hostname or IP. Choose it in the link box above and press Link.", $all);

            return $finish("This service is not linked to a {$machine} on the {$provider} account, so every client server button opens a ticket.");
        }

        $label = (string) $resolved['ref'];
        $known = false;

        foreach ($listing['services'] as $row) {
            // InterServer still accepts the legacy integer id, so a link stored that way counts.
            if (in_array((string) $resolved['ref'], [(string) ($row['ref'] ?? ''), (string) ($row['id'] ?? '')], true)) {
                $label = (string) ($row['label'] ?? $label);
                $known = true;
            }
        }

        $via = ['linked' => 'linked by an admin', 'hostname' => 'matched by hostname', 'ip' => 'matched by IP', 'username' => 'matched by username'][$resolved['via']] ?? (string) $resolved['via'];
        $add(
            'link',
            "Which {$machine} this service is",
            $known ? true : false,
            $known
                ? "{$label} ({$via})."
                : "Linked to \"{$label}\", but no {$machine} with that id is on the {$provider} account any more. Link it again.",
            $known ? '' : $all
        );

        if (!$known) {
            return $finish("The linked {$machine} is no longer on the {$provider} account.");
        }

        // Read-only calls, each named after the buttons that need it.
        $reads = $provider === 'Nocix'
            ? [
                ['osTemplates', 'templates', 'Operating systems (os-list)', 'OS reload'],
                ['reloadStatus', null, 'OS reload status (reloadstatus)', 'OS reload progress, Show login details'],
            ]
            : [
                ['info', null, 'Live details (GET /vps/{id})', 'status panel, VNC console'],
                ['reverseDnsEntries', 'ips', 'Reverse DNS (GET /vps/{id}/reverse_dns)', 'reverse DNS / PTR'],
                ['listBackups', 'backups', 'Backups (GET /vps/{id}/backups)', 'snapshot list, restore'],
                ['osTemplates', 'templates', 'OS templates (GET /vps/{id}/reinstall_os)', 'OS reinstall'],
            ];

        foreach ($reads as [$method, $listKey, $label, $affects]) {
            if (!method_exists($module, $method)) {
                continue;
            }

            try {
                $result = $module->{$method}($params);
            } catch (\Throwable $e) {
                $result = ['success' => false, 'message' => $e->getMessage()];
            }

            $detail = '';

            if ($result['success'] && $listKey !== null) {
                $n = count((array) ($result[$listKey] ?? []));
                $detail = match ($listKey) {
                    'ips' => "{$n} IP" . ($n === 1 ? '' : 's') . ' returned.',
                    'backups' => $n === 0 ? 'No backups yet (the client can take one with Snapshot).' : "{$n} backup" . ($n === 1 ? '' : 's') . ' found.',
                    default => $n === 0 ? 'The provider listed no operating systems for this machine.' : "{$n} operating system" . ($n === 1 ? '' : 's') . ' offered.',
                };
            } elseif ($result['success'] && $method === 'info') {
                $info = (array) ($result['info'] ?? []);
                $detail = trim('Status: ' . ((string) ($info['status'] ?? '') ?: 'not reported') . ((string) ($info['serviceStatus'] ?? '') !== '' && ($info['serviceStatus'] ?? '') !== ($info['status'] ?? '') ? " (service {$info['serviceStatus']})" : '') . '.');
            } elseif ($result['success'] && $method === 'reloadStatus') {
                $detail = ($result['status'] ?? null) === null ? 'No OS reload has been run yet.' : 'Latest reload: ' . (string) $result['status'] . '.';
            }

            $add(
                $method,
                $label,
                (bool) $result['success'],
                $result['success'] ? ($detail !== '' ? $detail : 'OK.') : ((string) ($result['message'] ?? '') ?: 'Failed with no message.'),
                $result['success'] ? '' : $affects
            );
        }

        if ($provider === 'InterServer') {
            $hasPassword = ((string) ($params['server']['account_password'] ?? '')) !== '';
            $add(
                'account_password',
                'InterServer account password (for reinstall and restore)',
                $hasPassword ? true : false,
                $hasPassword
                    ? 'Saved on the server record. It is only checked by InterServer when a reinstall or restore actually runs.'
                    : 'Not saved. InterServer re-checks the account password on OS reinstall and backup restore, so those two open tickets until it is saved (Admin → Servers → edit → Account password).',
                $hasPassword ? '' : 'OS reinstall, backup restore'
            );
        }

        $failed = array_values(array_filter($steps, static fn (array $s): bool => $s['ok'] === false));

        return $finish($failed === []
            ? "Everything the client's server buttons need answered correctly. Power, snapshot and console are not run by this check because they change the machine."
            : count($failed) . ' check' . (count($failed) === 1 ? '' : 's') . ' failed. The buttons listed against them open support tickets until it is fixed.');
    }

    /** Whether the server behind this id runs a module that links remote machines. */
    public function serverLinksRemoteServices(?int $serverId): bool
    {
        if ($serverId === null || $serverId <= 0) {
            return false;
        }

        $server = $this->servers->find($serverId);

        return $server !== null && $this->resolveModule((string) $server['module_slug']) instanceof LinksRemoteServices;
    }

    /**
     * State of the latest OS reload, for modules whose reload is queued and polled
     * (Nocix). `status` is null when there has never been one.
     *
     * @return array{success: bool, message: string, status?: ?string}
     */
    public function reloadStatus(int $serviceId): array
    {
        return $this->optional($serviceId, 'reloadStatus', [], 'This server module does not report OS reload progress.');
    }

    /**
     * The server login the provider keeps (Nocix sets it on OS reload). Sensitive:
     * show it to the owner, never log or store it.
     *
     * @return array{success: bool, message: string, username?: string, password?: string}
     */
    public function serverCredentials(int $serviceId): array
    {
        return $this->optional($serviceId, 'serverCredentials', [], 'This server module cannot read the server login.');
    }

    /** @return array{success: bool, message: string, templates?: array<int, array<string, mixed>>} */
    public function osTemplates(int $serviceId): array
    {
        return $this->optional($serviceId, 'osTemplates', [], 'This server module does not publish OS templates.');
    }

    /**
     * Renames the primary domain on the live server, then — only once the
     * module confirms it — updates the local `services.domain` column to
     * match. Ordered this way deliberately: a module failure must never
     * leave the local record claiming a domain the server doesn't actually
     * have.
     */
    public function changeDomain(int $serviceId, string $newDomain): array
    {
        [$module, $params, $error] = $this->moduleAndParamsFor($serviceId);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        if (!method_exists($module, 'changeDomain')) {
            return ['success' => false, 'message' => 'Changing the primary domain is not supported by this server module.'];
        }

        $result = $module->changeDomain(array_merge($params, ['domain' => $newDomain]));

        if (!$result['success']) {
            $this->recordFailure($serviceId, $result['message']);

            return $result;
        }

        $this->services->updateDetails($serviceId, ['domain' => $newDomain]);
        $this->clearFailure($serviceId);
        $this->hooks->fire(HookPoints::AFTER_MODULE_CHANGE_DOMAIN, ['serviceId' => $serviceId, 'domain' => $newDomain]);

        return $result;
    }

    /**
     * Asks the service's server whether $newUsername is free (cPanel
     * `verify_new_username`). Read-only.
     *
     * @return array{success: bool, available: bool, reachable: bool, message: string}
     */
    public function verifyNewUsername(int $serviceId, string $newUsername): array
    {
        [$module, $params, $error] = $this->moduleAndParamsFor($serviceId);

        if ($error !== null || !method_exists($module, 'verifyNewUsername')) {
            return ['success' => false, 'available' => false, 'reachable' => false, 'message' => $error ?? 'This server module cannot check usernames.'];
        }

        return $module->verifyNewUsername(array_merge($params, ['new_username' => $newUsername]));
    }

    /**
     * Whether $username exists as an account on the service's server.
     *
     * @return array{known: bool, exists: bool, domain: ?string, message: string}
     */
    public function accountExists(int $serviceId, string $username): array
    {
        [$module, $params, $error] = $this->moduleAndParamsFor($serviceId);

        if ($error !== null || !method_exists($module, 'accountExists')) {
            return ['known' => false, 'exists' => false, 'domain' => null, 'message' => $error ?? 'This server module cannot look up accounts.'];
        }

        return $module->accountExists(array_merge($params, ['username' => $username]));
    }

    /**
     * Renames the account on the live server, then — only once the server
     * confirms the new name exists — updates `services.username`. The same
     * order changeDomain() uses: a failed rename never leaves WHMP claiming a
     * name the server does not have.
     *
     * A dropped connection is not a failure by itself: WHM finishes the job
     * after the socket closes, so accountsummary on the NEW name decides.
     *
     * @return array{success: bool, renamed: bool, synced: bool, transient: bool, message: string, raw: string}
     */
    public function changeUsername(int $serviceId, string $newUsername, bool $renameDatabases = false, ?int $requestId = null, int $verifyAttempts = 3, int $verifyDelaySeconds = 3): array
    {
        [$module, $params, $error] = $this->moduleAndParamsFor($serviceId);

        if ($error !== null) {
            return ['success' => false, 'renamed' => false, 'synced' => false, 'transient' => false, 'message' => $error, 'raw' => ''];
        }

        if (!method_exists($module, 'changeUsername')) {
            return ['success' => false, 'renamed' => false, 'synced' => false, 'transient' => false, 'message' => 'Changing the username is not supported by this server module.', 'raw' => ''];
        }

        $oldUsername = (string) $params['username'];
        $result = $module->changeUsername(array_merge($params, ['new_username' => $newUsername, 'rename_db' => $renameDatabases]));
        $raw = (string) ($result['raw'] ?? '');
        $transport = !empty($result['transport_error']);

        $renamed = false;

        if (($result['success'] ?? false) || $transport) {
            for ($i = 0; $i < max(1, $verifyAttempts); $i++) {
                $check = method_exists($module, 'accountExists')
                    ? $module->accountExists(array_merge($params, ['username' => $newUsername]))
                    : ['known' => true, 'exists' => (bool) ($result['success'] ?? false)];

                if ($check['known'] && $check['exists']) {
                    $renamed = true;
                    break;
                }

                if ($check['known'] && !$transport) {
                    break;
                }

                if ($i + 1 < $verifyAttempts && $verifyDelaySeconds > 0) {
                    sleep($verifyDelaySeconds);
                }
            }
        }

        if (!$renamed) {
            $message = ($result['success'] ?? false)
                ? 'The server accepted the rename but the new account name could not be confirmed.'
                : (string) ($result['message'] ?? 'Rename failed.');
            $this->recordFailure($serviceId, $message);

            return ['success' => false, 'renamed' => false, 'synced' => false, 'transient' => $transport, 'message' => $message, 'raw' => $raw];
        }

        $synced = true;

        try {
            $this->services->updateDetails($serviceId, ['username' => $newUsername]);
        } catch (\Throwable) {
            $synced = false;
        }

        $this->clearFailure($serviceId);
        $this->hooks->fire(HookPoints::AFTER_MODULE_CHANGE_USERNAME, [
            'serviceId' => $serviceId,
            'oldUsername' => $oldUsername,
            'newUsername' => $newUsername,
            'requestId' => $requestId,
        ]);

        return ['success' => true, 'renamed' => true, 'synced' => $synced, 'transient' => false, 'message' => 'Username changed.', 'raw' => $raw];
    }

    /**
     * Every account on a server as [username => domain], for the local
     * availability cache. Empty + success=false when the server cannot answer.
     *
     * @return array{success: bool, accounts: array<string, string>, message: string}
     */
    public function serverAccounts(int $serverId): array
    {
        [$module, $server] = $this->moduleForServer($serverId);

        if ($module === null || !method_exists($module, 'listAccounts')) {
            return ['success' => false, 'accounts' => [], 'message' => 'Server cannot list accounts.'];
        }

        return $module->listAccounts(['server' => $server]);
    }

    /** 'mysql' | 'mariadb' | null when unknown. */
    public function serverDatabaseEngine(int $serverId): ?string
    {
        [$module, $server] = $this->moduleForServer($serverId);

        if ($module === null || !method_exists($module, 'databaseEngine')) {
            return null;
        }

        return $module->databaseEngine(['server' => $server]);
    }

    /** @return array{0: ?ProvisioningModule, 1: array<string, mixed>} */
    private function moduleForServer(int $serverId): array
    {
        $server = $this->servers->find($serverId);

        if ($server === null) {
            return [null, []];
        }

        $module = $this->resolveModule((string) $server['module_slug']);

        return [$module, $module === null ? [] : $this->withSecrets($server)];
    }

    /**
     * Switches the account to a new hosting package on the live server (WHM
     * changepackage for cPanel). The billing-side upgrade (product/price) is
     * handled separately by ProrationService; this only pushes the new
     * package to the server, ordered after the local record is updated.
     *
     * Runs in the background via UpgradePackageJob because a live
     * changepackage can be slow — the admin's browser should not wait on it.
     */
    public function changePackage(int $serviceId, string $package): array
    {
        [$module, $params, $error] = $this->moduleAndParamsFor($serviceId);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        if (!method_exists($module, 'changePackage')) {
            return ['success' => false, 'message' => 'Changing the hosting package is not supported by this server module.'];
        }

        $result = $module->changePackage(array_merge($params, ['package' => $package]));

        if (!$result['success']) {
            $this->recordFailure($serviceId, $result['message']);

            return $result;
        }

        $this->clearFailure($serviceId);
        $this->hooks->fire(HookPoints::AFTER_MODULE_CHANGE_PACKAGE, ['serviceId' => $serviceId, 'package' => $package]);

        return $result;
    }

    /**
     * Changes the account's password on the live server (WHM passwd for
     * cPanel). Runs in the background via ChangeServicePasswordJob because a
     * live passwd call can be slow — the client's browser should not wait on
     * it. The local `services.password` record is updated only after the
     * module confirms success (see ChangeServicePasswordJob).
     */
    public function changePassword(int $serviceId, string $password): array
    {
        return $this->optional($serviceId, 'changePassword', ['password' => $password], 'Changing the password is not supported by this server module.');
    }

    public function setReverseDns(int $serviceId, string $rdns, string $ip = ''): array
    {
        return $this->optional($serviceId, 'setReverseDns', [
            'rdns' => $rdns,
            'ip' => $ip,
        ], 'Reverse DNS configuration is not supported by this server module.');
    }

    /** @return array{success: bool, message: string, ips?: array<string, string>} */
    public function reverseDnsEntries(int $serviceId): array
    {
        return $this->optional($serviceId, 'reverseDnsEntries', [], 'Reverse DNS lookup is not supported by this server module.');
    }

    /**
     * Power state. Unlike suspend()/unsuspend(), this deliberately does not
     * touch the local service status: a client rebooting their own VPS is
     * not a billing-lifecycle transition, and recording it as one would let
     * a reboot silently mark an active service suspended.
     */
    public function power(int $serviceId, string $action): array
    {
        [$module, $params, $error] = $this->moduleAndParamsFor($serviceId);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        if (!method_exists($module, 'power')) {
            return ['success' => false, 'message' => 'Power control is not supported by this server module.'];
        }

        return $module->power($params, $action);
    }

    public function createBackup(int $serviceId): array
    {
        return $this->optional($serviceId, 'createBackup', [], 'Snapshots are not supported by this server module.');
    }

    /** @return array{success: bool, message: string, backups?: array<int, array<string, mixed>>} */
    public function listBackups(int $serviceId): array
    {
        return $this->optional($serviceId, 'listBackups', [], 'Snapshots are not supported by this server module.');
    }

    /** @return array{success: bool, message: string, slices?: array<string, mixed>} */
    public function sliceOptions(int $serviceId): array
    {
        return $this->optional($serviceId, 'sliceOptions', [], 'Slice scaling is not supported by this server module.');
    }

    /** @return array{success: bool, message: string, info?: array<string, mixed>} */
    public function remoteInfo(int $serviceId): array
    {
        return $this->optional($serviceId, 'info', [], 'This server module does not report live status.');
    }

    /**
     * Invokes a method a module only optionally implements. Power control,
     * snapshots and slice pricing are real on a hypervisor-backed VPS and
     * meaningless on a shared-hosting panel, so `ProvisioningModule` does
     * not mandate them — a module without one says so rather than letting
     * the caller assume the action happened.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function optional(int $serviceId, string $method, array $extra, string $unsupported): array
    {
        [$module, $params, $error] = $this->moduleAndParamsFor($serviceId);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        if (!method_exists($module, $method)) {
            return ['success' => false, 'message' => $unsupported];
        }

        return $module->{$method}(array_merge($params, $extra));
    }

    /** @return array<string, mixed> */
    public function usage(int $serviceId): array
    {
        [$module, $params, $error] = $this->moduleAndParamsFor($serviceId);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        return $module->usage($params);
    }

    /**
     * @param callable(ProvisioningModule, array): array $moduleAction
     * @param callable(int): void $onSuccess local status transition to apply once the module call succeeds
     */
    private function transition(int $serviceId, callable $moduleAction, callable $onSuccess, string $hookPoint, string $newStatus): array
    {
        [$module, $params, $error] = $this->moduleAndParamsFor($serviceId);

        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        $result = $moduleAction($module, $params);

        if (!$result['success']) {
            $this->recordFailure($serviceId, $result['message']);

            return $result;
        }

        $onSuccess($serviceId);
        $this->clearFailure($serviceId);
        $this->hooks->fire($hookPoint, ['serviceId' => $serviceId]);
        $this->hooks->fire(HookPoints::SERVICE_STATUS_CHANGED, ['serviceId' => $serviceId, 'status' => $newStatus]);

        return $result;
    }

    /**
     * @return array{0: ?ProvisioningModule, 1: array<string, mixed>, 2: ?string}
     */
    private function moduleAndParamsFor(int $serviceId): array
    {
        $service = $this->services->find($serviceId);

        if ($service === null) {
            return [null, [], 'Service not found.'];
        }

        if ($service['server_id'] === null) {
            return [null, [], 'Service has not been provisioned yet.'];
        }

        $server = $this->servers->find((int) $service['server_id']);

        if ($server === null) {
            return [null, [], 'Assigned server no longer exists.'];
        }

        $module = $this->resolveModule($server['module_slug']);

        if ($module === null) {
            return [null, [], "Unknown provisioning module \"{$server['module_slug']}\"."];
        }

        // A username is how most modules address an account (cPanel), so without one
        // the service is not provisioned. A module that links remote machines finds
        // its machine by remote_id, hostname or IP instead, so a VPS set up by hand
        // needs no WHMP username.
        if ($service['username'] === null && !$module instanceof LinksRemoteServices) {
            return [null, [], 'Service has not been provisioned yet.'];
        }

        return [$module, [
            'username' => $service['username'],
            'server' => $this->withSecrets($server),
            'domain' => $service['domain'] ?? null,
            'hostname' => $service['hostname'] ?? null,
            'password' => $service['password'] ?? null,
            'product_name' => $service['product_name'] ?? '',
            'remote_id' => $service['remote_id'] ?? null,
            'dedicated_ip' => $service['dedicated_ip'] ?? null,
            'assigned_ips' => $service['assigned_ips'] ?? null,
        ], null];
    }

    /**
     * The server row with its provider account password decrypted into
     * `account_password`, when one is stored and can be decrypted. The ciphertext
     * itself is never handed to a module.
     *
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    private function withSecrets(array $server): array
    {
        $stored = $server['account_secret'] ?? null;
        unset($server['account_secret']);

        if ($this->secrets !== null && is_string($stored) && $stored !== '') {
            $plain = $this->secrets->decrypt($stored);

            if ($plain !== null && $plain !== '') {
                $server['account_password'] = $plain;
            }
        }

        return $server;
    }

    private function resolveModule(string $slug): ?ProvisioningModule
    {
        /** @var ProvisioningModule|null $module */
        $module = $this->modules->get(ProvisioningModule::class, $slug);

        return $module;
    }

    private function generateUsername(int $serviceId): string
    {
        $service = $this->services->find($serviceId);
        $domain = $service['domain'] ?? $service['hostname'] ?? '';

        $settingsRepo = \CodeVault\Support\App::container()->make(\CodeVault\Settings\SettingsRepository::class);
        $randomEnabled = $settingsRepo->get('cpanel.random_usernames', '1') === '1';

        if (!$randomEnabled && !empty($domain)) {
            // Strip TLD / non-alphanumeric chars and get first 6 lowercase letters
            $clean = strtolower(preg_replace('/[^a-zA-Z]/', '', explode('.', $domain)[0]));
            if (strlen($clean) >= 3) {
                return substr($clean, 0, 8);
            }
        }

        // WHMCS-style random 8-character alphanumeric username starting with a letter
        $letters = 'abcdefghijklmnopqrstuvwxyz';
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $user = $letters[random_int(0, 25)];
        for ($i = 0; $i < 7; $i++) {
            $user .= $chars[random_int(0, 35)];
        }

        return $user;
    }

    private function recordFailure(int $serviceId, string $message): void
    {
        $this->services->recordProvisioningError($serviceId, $message);
    }

    private function clearFailure(int $serviceId): void
    {
        $this->services->recordProvisioningError($serviceId, null);
    }
}
