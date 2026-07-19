<?php

declare(strict_types=1);

namespace CodeVault\Tests\Fixtures;

use CodeVault\Modules\RegistrarModule;

/**
 * A scripted RegistrarModule double — lets DomainService tests verify its
 * own persistence logic (e.g. saving a newly-created registrarClientId/
 * registrarContactId) without depending on a specific real registrar's
 * HTTP behavior. Every call is recorded so a test can assert on the exact
 * params DomainService built.
 */
final class FakeRegistrarModule implements RegistrarModule
{
    /** @var array<int, array{method: string, params: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array<string, mixed>> method => canned return value */
    private array $responses = [];

    public function respond(string $method, array $response): void
    {
        $this->responses[$method] = $response;
    }

    public function lastCall(string $method): ?array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['method'] === $method) {
                return $call['params'];
            }
        }

        return null;
    }

    public function metadata(): array
    {
        return ['name' => 'Fake', 'description' => 'Test double', 'version' => '1.0.0', 'author' => 'Tests'];
    }

    public function configOptions(): array
    {
        return [];
    }

    public function register(array $params): array
    {
        return $this->record('register', $params);
    }

    public function transfer(array $params): array
    {
        return $this->record('transfer', $params);
    }

    public function renew(array $params): array
    {
        return $this->record('renew', $params);
    }

    public function getNameservers(array $params): array
    {
        return $this->record('getNameservers', $params, ['success' => true, 'nameservers' => []]);
    }

    public function saveNameservers(array $params): array
    {
        return $this->record('saveNameservers', $params);
    }

    public function getContactInfo(array $params): array
    {
        return $this->record('getContactInfo', $params, ['success' => true, 'contacts' => []]);
    }

    public function saveContactInfo(array $params): array
    {
        return $this->record('saveContactInfo', $params);
    }

    public function getRegistrarLock(array $params): array
    {
        return $this->record('getRegistrarLock', $params, ['success' => true, 'locked' => false]);
    }

    public function setRegistrarLock(array $params): array
    {
        return $this->record('setRegistrarLock', $params);
    }

    public function getEppCode(array $params): array
    {
        return $this->record('getEppCode', $params);
    }

    public function enableIdProtection(array $params): array
    {
        return $this->record('enableIdProtection', $params);
    }

    public function disableIdProtection(array $params): array
    {
        return $this->record('disableIdProtection', $params);
    }

    public function checkAvailability(array $params): array
    {
        return $this->record('checkAvailability', $params, ['success' => true, 'available' => true, 'expiryDate' => '', 'status' => 'checked']);
    }

    public function sync(array $params): array
    {
        return $this->record('sync', $params);
    }

    /** @param array<string, mixed> $params */
    private function record(string $method, array $params, array $default = ['success' => true, 'message' => 'OK']): array
    {
        $this->calls[] = ['method' => $method, 'params' => $params];

        return $this->responses[$method] ?? $default;
    }
}
