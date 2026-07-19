<?php

declare(strict_types=1);

namespace CodeVault\Modules;

/**
 * The activation half of the WidgetModule lifecycle (mirrors
 * AddonModuleService from R20). Unlike an addon, a widget has no hooks to
 * wire and no activate()/deactivate() side effects to call — the only
 * thing activation state controls is whether a placement's render pass
 * includes it, via activeWidgetsForPlacement().
 */
final class WidgetModuleService
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly WidgetModuleRepository $repository
    ) {
    }

    /** @return array{success: bool, message: string} */
    public function activate(string $slug): array
    {
        if (!$this->find($slug) instanceof WidgetModule) {
            return ['success' => false, 'message' => "Unknown widget [{$slug}]."];
        }

        $this->repository->activate($slug);

        return ['success' => true, 'message' => 'Widget activated.'];
    }

    /** @return array{success: bool, message: string} */
    public function deactivate(string $slug): array
    {
        if (!$this->find($slug) instanceof WidgetModule) {
            return ['success' => false, 'message' => "Unknown widget [{$slug}]."];
        }

        $this->repository->deactivate($slug);

        return ['success' => true, 'message' => 'Widget deactivated.'];
    }

    public function find(string $slug): ?WidgetModule
    {
        $module = $this->modules->get(WidgetModule::class, $slug);

        return $module instanceof WidgetModule ? $module : null;
    }

    /**
     * @return array<int, array{slug: string, metadata: array, active: bool}>
     */
    public function catalog(): array
    {
        $entries = [];

        foreach ($this->modules->allOfType(WidgetModule::class) as $slug => $module) {
            if (!$module instanceof WidgetModule) {
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

    /**
     * Registered, active, and matching this placement — what a page that
     * hosts widgets (e.g. the admin dashboard) actually renders.
     *
     * @return array<int, WidgetModule>
     */
    public function activeWidgetsForPlacement(string $placement): array
    {
        $widgets = [];

        foreach ($this->modules->allOfType(WidgetModule::class) as $slug => $module) {
            if (!$module instanceof WidgetModule) {
                continue;
            }

            if ($module->placement() !== $placement) {
                continue;
            }

            if (!$this->repository->isActive($slug)) {
                continue;
            }

            $widgets[] = $module;
        }

        return $widgets;
    }
}
