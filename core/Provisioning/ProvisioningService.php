<?php

declare(strict_types=1);

namespace CodeVault\Provisioning;

use CodeVault\Billing\ServiceRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Modules\ModuleManager;
use CodeVault\Modules\ProvisioningModule;

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
        private readonly HookDispatcher $hooks
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

    public function suspend(int $serviceId): array
    {
        return $this->transition(
            $serviceId,
            fn (ProvisioningModule $m, array $p) => $m->suspend($p),
            fn (int $id) => $this->services->suspend($id),
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

    public function reinstall(int $serviceId, string $template, string $localPassword = ''): array
    {
        return $this->optional($serviceId, 'reinstall', [
            'template' => $template,
            'localPassword' => $localPassword,
        ], 'OS reinstallation is not supported by this server module.');
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

        if ($service['server_id'] === null || $service['username'] === null) {
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

        return [$module, [
            'username' => $service['username'],
            'server' => $server,
            'domain' => $service['domain'] ?? null,
            'hostname' => $service['hostname'] ?? null,
            'password' => $service['password'] ?? null,
            'product_name' => $service['product_name'] ?? '',
        ], null];
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
