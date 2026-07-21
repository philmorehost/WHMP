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
        return "cv{$serviceId}";
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
