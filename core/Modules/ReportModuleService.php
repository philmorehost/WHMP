<?php

declare(strict_types=1);

namespace CodeVault\Modules;

/**
 * The activation half of the ReportModule lifecycle (mirrors
 * WidgetModuleService from R21). Unlike an addon, a report has no hooks to
 * wire and no activate()/deactivate() side effects to call — activation
 * state only controls whether it's listed and runnable under
 * Admin → Reports, enforced by run() so a deactivated report can't be
 * executed via a direct URL even if its slug is still known.
 */
final class ReportModuleService
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly ReportModuleRepository $repository
    ) {
    }

    /** @return array{success: bool, message: string} */
    public function activate(string $slug): array
    {
        if (!$this->find($slug) instanceof ReportModule) {
            return ['success' => false, 'message' => "Unknown report [{$slug}]."];
        }

        $this->repository->activate($slug);

        return ['success' => true, 'message' => 'Report activated.'];
    }

    /** @return array{success: bool, message: string} */
    public function deactivate(string $slug): array
    {
        if (!$this->find($slug) instanceof ReportModule) {
            return ['success' => false, 'message' => "Unknown report [{$slug}]."];
        }

        $this->repository->deactivate($slug);

        return ['success' => true, 'message' => 'Report deactivated.'];
    }

    public function find(string $slug): ?ReportModule
    {
        $module = $this->modules->get(ReportModule::class, $slug);

        return $module instanceof ReportModule ? $module : null;
    }

    /**
     * @return array<int, array{slug: string, metadata: array, active: bool}>
     */
    public function catalog(): array
    {
        $entries = [];

        foreach ($this->modules->allOfType(ReportModule::class) as $slug => $module) {
            if (!$module instanceof ReportModule) {
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
     * Runs an active report's generate(), or fails plainly for an unknown
     * or deactivated slug — activation state is enforced here, not just in
     * the admin UI, so a deactivated report can't be run via a direct URL.
     *
     * @param array<string, mixed> $filters
     * @return array{success: bool, message: string, columns?: array<int, string>, rows?: array<int, array<int, mixed>>}
     */
    public function run(string $slug, array $filters): array
    {
        $module = $this->find($slug);

        if ($module === null) {
            return ['success' => false, 'message' => "Unknown report [{$slug}]."];
        }

        if (!$this->repository->isActive($slug)) {
            return ['success' => false, 'message' => "Report [{$slug}] is not active."];
        }

        $result = $module->generate($filters);

        return ['success' => true, 'message' => '', 'columns' => $result['columns'], 'rows' => $result['rows']];
    }
}
