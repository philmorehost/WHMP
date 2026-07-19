<?php

declare(strict_types=1);

namespace CodeVault\Tests\Fixtures;

use CodeVault\Provisioning\HttpClient;

/**
 * Records every request it receives and returns a scripted response —
 * lets provisioning-module tests assert the exact URL/headers/body built
 * for a real API without touching the network.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @var array{status: int, body: string} */
    private array $response;

    public function __construct(int $responseStatus = 200, string $responseBody = '{}')
    {
        $this->response = ['status' => $responseStatus, 'body' => $responseBody];
    }

    public function respondWith(int $status, string $body): void
    {
        $this->response = ['status' => $status, 'body' => $body];
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');

        return $this->response;
    }

    public function lastRequest(): ?array
    {
        return $this->requests[count($this->requests) - 1] ?? null;
    }
}
