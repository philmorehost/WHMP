<?php

declare(strict_types=1);

namespace CodeVault\Modules;

use CodeVault\Hooks\HookDispatcher;
use InvalidArgumentException;

/**
 * Registry for every module instance the app knows about, keyed by module
 * type + a short slug (e.g. "gateway:paystack", "server:cpanel"). Doesn't
 * do filesystem discovery yet (that lands with the Setup/Configuration UI
 * in R3+) — for now, modules register themselves explicitly, which is
 * enough for the core to depend on a stable contract from day one.
 */
class ModuleManager
{
    /** @var array<string, array<string, Module>> */
    private array $modules = [];

    private const TYPES = [
        ProvisioningModule::class,
        RegistrarModule::class,
        GatewayModule::class,
        AddonModule::class,
        ReportModule::class,
        NotificationModule::class,
        WidgetModule::class,
        SecurityQuestionModule::class,
        FraudModule::class,
    ];

    public function __construct(
        private readonly HookDispatcher $hooks
    ) {
    }

    public function register(string $type, string $slug, Module $module): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown module type [{$type}].");
        }

        if (!$module instanceof $type) {
            throw new InvalidArgumentException(
                "Module [{$slug}] does not implement [{$type}]."
            );
        }

        $this->modules[$type][$slug] = $module;
    }

    public function get(string $type, string $slug): ?Module
    {
        return $this->modules[$type][$slug] ?? null;
    }

    /** @return array<string, Module> */
    public function allOfType(string $type): array
    {
        return $this->modules[$type] ?? [];
    }

    public function has(string $type, string $slug): bool
    {
        return isset($this->modules[$type][$slug]);
    }
}
