<?php

declare(strict_types=1);

namespace CodeVault\Seo;

use CodeVault\Kernel;
use CodeVault\Request;

/**
 * Dispatches a synthetic GET straight through Kernel::handle(), in the
 * same PHP process — no network round trip to itself. A real HTTP
 * self-fetch deadlocks under any single-worker server (the built-in PHP
 * dev server included) since the worker handling this request is the
 * only one available to answer its own outgoing request.
 */
final class InProcessPageFetcher implements PageFetcher
{
    public function __construct(
        private readonly Kernel $kernel
    ) {
    }

    public function fetch(string $path): array
    {
        $request = new Request(
            query: [],
            body: [],
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '127.0.0.1'],
            headers: []
        );

        $response = $this->kernel->handle($request);

        return ['status' => $response->status(), 'body' => $response->body()];
    }
}
