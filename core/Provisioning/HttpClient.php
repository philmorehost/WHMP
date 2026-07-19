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
     * @return array{status: int, body: string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array;
}
