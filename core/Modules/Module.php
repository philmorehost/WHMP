<?php

declare(strict_types=1);

namespace CodeVault\Modules;

/**
 * Base contract every module type extends. Keeps admin-UI concerns
 * (display name, version, configurable fields) out of each specific
 * interface so ProvisioningModule/GatewayModule/etc. only declare the
 * behavior unique to that module type.
 */
interface Module
{
    /**
     * @return array{name: string, description: string, version: string, author: string}
     */
    public function metadata(): array;

    /**
     * Fields rendered on the module's config page (server/gateway/registrar
     * settings, API keys, etc.) — mirrors WHMCS's `_config()` array shape.
     *
     * @return array<string, array{type: string, label: string, default?: mixed, options?: array}>
     */
    public function configOptions(): array;
}
