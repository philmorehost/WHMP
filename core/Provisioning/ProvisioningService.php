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

    /** Whether the server behind this id runs a module that links remote machines. */
    public function serverLinksRemoteServices(?int $serverId): bool
    {
        if ($serverId === null || $serverId <= 0) {
            return false;
        }

        $server = $this->servers->find($serverId);

        return $server !== null && $this->resolveModule((string) $server['module_slug']) instanceof LinksRemoteServices;
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
