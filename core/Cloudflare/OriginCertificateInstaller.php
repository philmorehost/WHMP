<?php

declare(strict_types=1);

namespace CodeVault\Cloudflare;

/** Installation adapter for Origin CA certificates; production uses cPanel UAPI. */
interface OriginCertificateInstaller
{
    public function supports(int $serviceId): bool;

    /** @return array{success: bool, message?: string} */
    public function install(int $serviceId, string $domain, string $certificate, string $privateKey): array;
}
