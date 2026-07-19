<?php

declare(strict_types=1);

namespace CodeVault\Provisioning;

use CodeVault\Modules\ProvisioningModule;

/**
 * CyberPanel server module, built against CyberPanel's REST-ish API
 * (JSON POST to `/api/...`, `adminUser`/`adminPass` credentials in the
 * body, `{status: 1|0, error_message}` response shape).
 *
 * CONFIDENCE NOTE: CyberPanel's API surface is far less standardized/stable
 * across versions than WHM API 1 — endpoint names and exact response
 * shapes have shifted release to release, and there's no live CyberPanel
 * server reachable from this environment to confirm against. This is a
 * best-effort implementation of the commonly-documented endpoints,
 * unit-tested for request-shape correctness against a fake HTTP client,
 * but it should be verified against a real instance's current API docs
 * before production use — more so than the cPanel module.
 */
final class CyberPanelProvisioningModule implements ProvisioningModule
{
    private const DEFAULT_PORT = 8090;

    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'CyberPanel',
            'description' => 'Creates and manages CyberPanel website accounts via its REST API.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [
            'default_package' => ['type' => 'text', 'label' => 'Default Package', 'default' => 'Default'],
            'owner_email' => ['type' => 'text', 'label' => 'Fallback Owner Email', 'default' => ''],
        ];
    }

    public function create(array $params): array
    {
        $server = $params['server'];
        $username = (string) $params['username'];

        return $this->call($server, 'createWebsite', [
            'domainName' => $params['domain'] ?? "{$username}.example.invalid",
            'ownerEmail' => $params['owner_email'] ?? ($server['owner_email'] ?? 'owner@example.invalid'),
            'packageName' => $server['default_package'] ?? 'Default',
            'websiteOwner' => $username,
            'ownerPassword' => $params['password'] ?? bin2hex(random_bytes(12)),
        ]);
    }

    public function suspend(array $params): array
    {
        return $this->call($params['server'], 'submitDomainSuspension', ['domainName' => (string) $params['username']]);
    }

    public function unsuspend(array $params): array
    {
        return $this->call($params['server'], 'submitDomainUnSuspension', ['domainName' => (string) $params['username']]);
    }

    public function terminate(array $params): array
    {
        return $this->call($params['server'], 'submitWebsiteDeletion', ['domainName' => (string) $params['username']]);
    }

    public function changePassword(array $params): array
    {
        return $this->call($params['server'], 'submitWebsitePasswordChange', [
            'domainName' => (string) $params['username'],
            'password' => (string) $params['password'],
        ]);
    }

    public function changePackage(array $params): array
    {
        return $this->call($params['server'], 'submitPackageChange', [
            'domainName' => (string) $params['username'],
            'packageName' => (string) ($params['package'] ?? 'Default'),
        ]);
    }

    public function singleSignOn(array $params): array
    {
        // CyberPanel does not expose a documented one-click-login token
        // API the way WHM does — flagged as unsupported rather than
        // guessing at an endpoint that may not exist.
        return ['success' => false, 'message' => 'Single sign-on is not supported by the CyberPanel module.'];
    }

    public function usage(array $params): array
    {
        $response = $this->call($params['server'], 'fetchWebsiteDiskAndBandwidthUsage', ['domainName' => (string) $params['username']]);

        if (!$response['success']) {
            return $response;
        }

        $data = $response['data'] ?? [];

        return [
            'success' => true,
            'diskUsedMb' => (float) ($data['diskUsed'] ?? 0),
            'diskLimitMb' => (float) ($data['diskLimit'] ?? 0),
            'bandwidthUsedMb' => (float) ($data['bandwidthUsed'] ?? 0),
            'bandwidthLimitMb' => (float) ($data['bandwidthLimit'] ?? 0),
        ];
    }

    public function testConnection(array $params): array
    {
        return $this->call($params['server'], 'verifyConn', []);
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $payload
     * @return array{success: bool, message: string, data?: array}
     */
    private function call(array $server, string $endpoint, array $payload): array
    {
        $port = $server['api_port'] ?? self::DEFAULT_PORT;
        $scheme = ($server['use_ssl'] ?? true) ? 'https' : 'http';
        $url = "{$scheme}://{$server['hostname']}:{$port}/api/{$endpoint}";

        $body = array_merge($payload, [
            'adminUser' => $server['api_username'] ?? '',
            'adminPass' => $server['api_token'] ?? '',
        ]);

        $response = $this->http->request('POST', $url, ['Content-Type' => 'application/json'], (string) json_encode($body));

        if ($response['status'] === 0) {
            return ['success' => false, 'message' => 'Could not reach the CyberPanel server.'];
        }

        $decoded = json_decode($response['body'], true);
        $success = (bool) ($decoded['status'] ?? 0);

        if ($response['status'] !== 200 || !$success) {
            return ['success' => false, 'message' => $decoded['error_message'] ?? "CyberPanel API error (HTTP {$response['status']})."];
        }

        return ['success' => true, 'message' => 'OK', 'data' => $decoded];
    }
}
