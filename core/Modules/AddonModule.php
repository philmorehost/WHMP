<?php

declare(strict_types=1);

namespace CodeVault\Modules;

/**
 * Installable admin-area apps/plugins (Setup → Addons). Distinct from a
 * product "Addon" (a recurring service add-on, §4.2) — this is code, not
 * billing data.
 */
interface AddonModule extends Module
{
    public function activate(): array;

    public function deactivate(): array;

    /**
     * Rendered inside the addon's own admin-area page.
     */
    public function render(array $params): string;

    /**
     * Hook points this addon wants to listen to and its handler for each,
     * registered automatically by the ModuleManager on activation.
     *
     * @return array<string, callable>
     */
    public function hooks(): array;
}
