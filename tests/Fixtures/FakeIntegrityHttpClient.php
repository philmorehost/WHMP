<?php

declare(strict_types=1);

namespace CodeVault\Tests\Fixtures;

use CodeVault\Integrity\IntegrityHttpClient;

final class FakeIntegrityHttpClient implements IntegrityHttpClient
{
    public int $calls = 0;

    /** @param array{ok: bool, status: int, body: array<string, mixed>} $response */
    public function __construct(
        private array $response
    ) {
    }

    public function post(string $url, array $payload, int $timeoutSeconds): array
    {
        $this->calls++;

        return $this->response;
    }
}
