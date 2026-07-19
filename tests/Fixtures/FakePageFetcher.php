<?php

declare(strict_types=1);

namespace CodeVault\Tests\Fixtures;

use CodeVault\Seo\PageFetcher;

final class FakePageFetcher implements PageFetcher
{
    /** @var array{status: int, body: string} */
    private array $response;

    /** @var array<int, string> */
    public array $fetchedPaths = [];

    public function __construct(int $responseStatus = 200, string $responseBody = '')
    {
        $this->response = ['status' => $responseStatus, 'body' => $responseBody];
    }

    public function respondWith(int $status, string $body): void
    {
        $this->response = ['status' => $status, 'body' => $body];
    }

    public function fetch(string $path): array
    {
        $this->fetchedPaths[] = $path;

        return $this->response;
    }
}
