<?php

declare(strict_types=1);

namespace CodeVault\Provisioning;

/**
 * The one seam between a provisioning module's request-building logic and
 * the network. Injected so cPanel/CyberPanel modules can be unit tested
 * against a fake implementation — there's no live server in this
 * environment to round-trip against, so this interface is what makes the
 * request-shape (URL, headers, payload) independently verifiable.
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * `status` is 0 when no HTTP response arrived; `error` then says why.
     *
     * @return array{status: int, body: string, error?: string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array;
}
