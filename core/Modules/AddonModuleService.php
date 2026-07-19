<?php

declare(strict_types=1);

namespace CodeVault\Modules;

use CodeVault\Hooks\HookDispatcher;

/**
 * The activation half of the AddonModule lifecycle. ModuleManager holds
 * every registered addon in memory unconditionally at boot (same as every
 * other module type); this service is what makes "registered" and "active"
 * different things — an addon's hooks() only reach the HookDispatcher once
 * an admin has actually turned it on via AddonController, and it stays that
 * way across requests via AddonModuleRepository.
 */
final class AddonModuleService
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly AddonModuleRepository $repository,
        private readonly HookDispatcher $hooks
    ) {
    }

    /**
     * Call once per request boot, after ModuleManager is fully populated.
     * Re-registering hooks on every request (rather than caching the wired
     * state) keeps this stateless and correct even though PHP's
     * request-per-process model means it re-runs every time anyway.
     */
    public function bootActiveAddons(): void
    {
        foreach ($this->modules->allOfType(AddonModule::class) as $slug => $module) {
            if (!$module instanceof AddonModule) {
                continue;
            }

            if (!$this->repository->isActive($slug)) {
                continue;
            }

            foreach ($module->hooks() as $hookPoint => $listener) {
                $this->hooks->register($hookPoint, $listener);
            }
        }
    }

    /** @return array{success: bool, message: string} */
    public function activate(string $slug): array
    {
        $module = $this->modules->get(AddonModule::class, $slug);
        if (!$module instanceof AddonModule) {
            return ['success' => false, 'message' => "Unknown addon [{$slug}]."];
        }

        $result = $module->activate();
        $this->repository->activate($slug);

        return $result;
    }

    /** @return array{success: bool, message: string} */
    public function deactivate(string $slug): array
    {
        $module = $this->modules->get(AddonModule::class, $slug);
        if (!$module instanceof AddonModule) {
            return ['success' => false, 'message' => "Unknown addon [{$slug}]."];
        }

        $result = $module->deactivate();
        $this->repository->deactivate($slug);

        return $result;
    }

    public function find(string $slug): ?AddonModule
    {
        $module = $this->modules->get(AddonModule::class, $slug);

        return $module instanceof AddonModule ? $module : null;
    }

    /**
     * @return array<int, array{slug: string, metadata: array, active: bool}>
     */
    public function catalog(): array
    {
        $entries = [];

        foreach ($this->modules->allOfType(AddonModule::class) as $slug => $module) {
            if (!$module instanceof AddonModule) {
                continue;
            }

            $entries[] = [
                'slug' => $slug,
                'metadata' => $module->metadata(),
                'active' => $this->repository->isActive($slug),
            ];
        }

        return $entries;
    }
}
