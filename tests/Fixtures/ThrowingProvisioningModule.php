<?php

declare(strict_types=1);

namespace CodeVault\Tests\Fixtures;

use CodeVault\Modules\ProvisioningModule;
use RuntimeException;

/**
 * A ProvisioningModule whose create() always throws — simulates a real
 * control-panel module blowing up at the HTTP layer. Used to prove the
 * accept job keeps going (and still registers the order's domains) when an
 * individual service's provisioning throws instead of returning a result.
 */
final class ThrowingProvisioningModule implements ProvisioningModule
{
    public int $createCalls = 0;

    public function metadata(): array
    {
        return ['name' => 'Throwing', 'description' => 'Always throws', 'version' => '1.0.0', 'author' => 'Tests'];
    }

    public function configOptions(): array
    {
        return [];
    }

    public function create(array $params): array
    {
        $this->createCalls++;

        throw new RuntimeException('WHM API call exploded: connection reset');
    }

    public function suspend(array $params): array
    {
        return ['success' => true, 'message' => ''];
    }

    public function unsuspend(array $params): array
    {
        return ['success' => true, 'message' => ''];
    }

    public function terminate(array $params): array
    {
        return ['success' => true, 'message' => ''];
    }

    public function changePassword(array $params): array
    {
        return ['success' => true, 'message' => ''];
    }

    public function changePackage(array $params): array
    {
        return ['success' => true, 'message' => ''];
    }

    public function singleSignOn(array $params): array
    {
        return ['success' => true, 'message' => ''];
    }

    public function usage(array $params): array
    {
        return ['success' => true, 'diskused' => 0, 'disklimit' => 0];
    }

    public function testConnection(array $params): array
    {
        return ['success' => true, 'message' => ''];
    }
}
