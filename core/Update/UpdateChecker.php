<?php

declare(strict_types=1);

namespace CodeVault\Update;

use CodeVault\Integrity\IntegrityHttpClient;

/**
 * Queries the management server's update feed (blueprint §5 — same server
 * as system activation, `manager.pmhserver.name.ng`) for whether a newer
 * version exists.
 */
final class UpdateChecker
{
    public function __construct(
        private readonly IntegrityHttpClient $http,
        private readonly string $feedUrl
    ) {
    }

    /**
     * @return array{updateAvailable: bool, latestVersion: ?string, changelog: ?string, downloadUrl: ?string, checksum: ?string}
     */
    public function check(string $activationKey, string $domain, string $currentVersion): array
    {
        $response = $this->http->post($this->feedUrl, [
            'key' => $activationKey,
            'domain' => $domain,
            'current_version' => $currentVersion,
        ], 8);

        if (!$response['ok']) {
            return ['updateAvailable' => false, 'latestVersion' => null, 'changelog' => null, 'downloadUrl' => null, 'checksum' => null];
        }

        $body = $response['body'];
        $latest = $body['latest_version'] ?? null;

        return [
            'updateAvailable' => $latest !== null && $latest !== $currentVersion,
            'latestVersion' => $latest,
            'changelog' => $body['changelog'] ?? null,
            'downloadUrl' => $body['download_url'] ?? null,
            'checksum' => $body['checksum'] ?? null,
        ];
    }
}
