<?php

declare(strict_types=1);

namespace CodeVault\Integrity;

/**
 * Boundary around the remote call to the management server
 * (`manager.pmhserver.name.ng`), kept as an interface so IntegrityManager's
 * cache/soft-grace/kill-switch logic is testable without a live network call.
 */
interface IntegrityHttpClient
{
    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, status: int, body: array<string, mixed>}
     */
    public function post(string $url, array $payload, int $timeoutSeconds): array;
}
