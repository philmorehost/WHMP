<?php

declare(strict_types=1);

namespace CodeVault\Tests\Fixtures;

use CodeVault\Modules\ProvisioningModule;

/**
 * A scriptable ProvisioningModule for job tests — lets a test drive the real
 * ProvisioningService (and therefore a real queue job) without touching the
 * network. The result of each lifecycle call is scripted via public
 * properties, and the module records every call it receives.
 */
final class FakeProvisioningModule implements ProvisioningModule
{
    /** @var array{success: bool, message: string} */
    public array $createResult = ['success' => true, 'message' => 'Account created.'];

    /** @var array{success: bool, message: string} */
    public array $changePackageResult = ['success' => true, 'message' => 'Package changed.'];

    /** @var array<int, array<string, mixed>> */
    public array $createCalls = [];

    /** @var array<int, array<string, mixed>> */
    public array $changePackageCalls = [];

    public function metadata(): array
    {
        return ['name' => 'Fake cPanel', 'description' => 'Test double', 'version' => '1.0.0', 'author' => 'Tests'];
    }

    public function configOptions(): array
    {
        return [];
    }

    public function create(array $params): array
    {
        $this->createCalls[] = $params;

        return $this->createResult;
    }

    public function changePackage(array $params): array
    {
        $this->changePackageCalls[] = $params;

        return $this->changePackageResult;
    }

    public function suspend(array $params): array
    {
        return ['success' => true, 'message' => 'Suspended.'];
    }

    public function unsuspend(array $params): array
    {
        return ['success' => true, 'message' => 'Unsuspended.'];
    }

    public function terminate(array $params): array
    {
        return ['success' => true, 'message' => 'Terminated.'];
    }

    public function changePassword(array $params): array
    {
        return ['success' => true, 'message' => 'Password changed.'];
    }

    public function singleSignOn(array $params): array
    {
        return ['success' => true, 'url' => 'https://panel.example.test', 'message' => 'SSO.'];
    }

    public function usage(array $params): array
    {
        return ['success' => true, 'diskUsedMb' => 0, 'diskLimitMb' => 1000];
    }

    public function testConnection(array $params): array
    {
        return ['success' => true, 'message' => 'Connected.'];
    }
}
