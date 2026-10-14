<?php

declare(strict_types=1);

namespace CodeVault\Cloudflare;

use CodeVault\CpanelTools\CpanelToolsService;
use Throwable;

/** Automatically installs Cloudflare Origin CA certificates on cPanel accounts. */
final class CpanelOriginInstaller implements OriginCertificateInstaller
{
    public function __construct(private readonly CpanelToolsService $cpanel)
    {
    }

    public function supports(int $serviceId): bool
    {
        try {
            return $this->cpanel->isCpanelService($serviceId);
        } catch (Throwable) {
            return false;
        }
    }

    public function install(int $serviceId, string $domain, string $certificate, string $privateKey): array
    {
        try {
            $result = $this->cpanel->installSslCertificate($serviceId, $domain, $certificate, $privateKey);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
        return ['success' => (bool) ($result['success'] ?? false), 'message' => (string) ($result['message'] ?? '')];
    }
}
