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

    /** @var array<int, array{status: int, body: string}> consumed before $response */
    private array $queue = [];

    public function __construct(int $responseStatus = 200, string $responseBody = '{}')
    {
        $this->response = ['status' => $responseStatus, 'body' => $responseBody];
    }

    public function respondWith(int $status, string $body): void
    {
        $this->response = ['status' => $status, 'body' => $body];
    }

    /**
     * Scripts a different answer per call, in order, for flows where one
     * canned response cannot represent the real API — a lookup that must miss
     * before a create, for instance. Anything past the end of the queue falls
     * back to respondWith()'s single response.
     *
     * @param array<int, array{status: int, body: string}> $responses
     */
    public function respondInSequence(array $responses): void
    {
        $this->queue = array_values($responses);
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');

        return array_shift($this->queue) ?? $this->response;
    }

    public function lastRequest(): ?array
    {
        return $this->requests[count($this->requests) - 1] ?? null;
    }
}
