<?php

declare(strict_types=1);

namespace CodeVault\Provisioning;

use CodeVault\Modules\ProvisioningModule;

/**
 * Dedicated Server provisioning against InterServer's real Management API
 * (blueprint §3 — `https://my.interserver.net/apiv2`, `X-API-KEY` header auth).
 * Endpoint paths and response parsing mimic the VPS module but targeting the dedicated server endpoints.
 */
final class InterServerDedicatedProvisioningModule implements ProvisioningModule
{
    private const BASE_URL = 'https://my.interserver.net/apiv2';

    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'InterServer Dedicated Server',
            'description' => 'Orders and manages dedicated servers via the InterServer Management API.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [
            'default_billing_cycle' => ['type' => 'text', 'label' => 'Default Billing Cycle', 'default' => 'monthly'],
        ];
    }

    public function create(array $params): array
    {
        $server = $params['server'];
        $hostname = (string) $params['username'];

        $body = [
            'hostname' => $hostname,
            'billing_cycle' => (string) ($params['billing_cycle'] ?? ($server['default_billing_cycle'] ?? 'monthly')),
            'ips' => 1,
        ];

        $response = $this->call($server, 'POST', '/dedicated/order', $body);
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message']];
        }

        $data = is_array($decoded['data']) ? $decoded['data'] : [];
        $serviceId = $data['serviceId'] ?? $data['serviceid'] ?? null;

        return [
            'success' => true,
            'message' => $serviceId !== null
                ? "Dedicated server order placed (service #{$serviceId})."
                : 'Dedicated server order placed.',
        ];
    }

    public function suspend(array $params): array
    {
        return $this->lifecycleAction($params, 'GET', '/dedicated/{id}/stop');
    }

    public function unsuspend(array $params): array
    {
        return $this->lifecycleAction($params, 'GET', '/dedicated/{id}/start');
    }

    public function terminate(array $params): array
    {
        return $this->lifecycleAction($params, 'DELETE', '/dedicated/{id}');
    }

    public function changePassword(array $params): array
    {
        return [
            'success' => false,
            'message' => 'Changing root password via API is not supported for dedicated servers. Use IPMI/KVM console.',
        ];
    }

    public function changePackage(array $params): array
    {
        return [
            'success' => false,
            'message' => 'Hardware modifications require manual server migration or order cancellation.',
        ];
    }

    public function singleSignOn(array $params): array
    {
        $dedicatedId = $this->resolveDedicatedId($params);

        if ($dedicatedId === null) {
            return ['success' => false, 'message' => 'Could not find this dedicated server on the hosting provider (hostname lookup failed).'];
        }

        $response = $this->call($params['server'], 'GET', "/dedicated/{$dedicatedId}/kvm", null);
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message']];
        }

        $data = is_array($decoded['data']) ? $decoded['data'] : [];
        $url = $data['kvm_url'] ?? $data['url'] ?? null;

        if ($url === null) {
            return [
                'success' => false,
                'message' => 'No KVM console is provisioned for this server yet.',
            ];
        }

        return ['success' => true, 'url' => $url, 'message' => 'KVM console URL retrieved.'];
    }

    public function usage(array $params): array
    {
        $dedicatedId = $this->resolveDedicatedId($params);

        if ($dedicatedId === null) {
            return ['success' => false, 'message' => 'Could not find this dedicated server on the hosting provider (hostname lookup failed).'];
        }

        $response = $this->call($params['server'], 'GET', "/dedicated/{$dedicatedId}/traffic_usage", null);
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message']];
        }

        $month = $decoded['data']['totals']['month'] ?? ['in' => 0, 'out' => 0];

        return [
            'success' => true,
            'bandwidthUsedMb' => round((((float) ($month['in'] ?? 0)) + ((float) ($month['out'] ?? 0))) / 1024 / 1024, 2),
            'bandwidthLimitMb' => null,
            'diskUsedMb' => null,
            'diskLimitMb' => null,
        ];
    }

    public function testConnection(array $params): array
    {
        $response = $this->call($params['server'], 'GET', '/dedicated', null);

        return $this->toResult($response, 'Connected — API key is valid.');
    }

    public function reinstall(array $params): array
    {
        $dedicatedId = $this->resolveDedicatedId($params);

        if ($dedicatedId === null) {
            return ['success' => false, 'message' => 'Could not find this dedicated server on the hosting provider (hostname lookup failed).'];
        }

        $osVersion = (string) ($params['osVersion'] ?? 'ubuntu24');

        $response = $this->call($params['server'], 'POST', "/dedicated/{$dedicatedId}/reinstall", [
            'osVersion' => $osVersion,
        ]);

        return $this->toResult($response, 'Dedicated server OS reinstallation has been queued.');
    }

    public function setReverseDns(array $params): array
    {
        $dedicatedId = $this->resolveDedicatedId($params);

        if ($dedicatedId === null) {
            return ['success' => false, 'message' => 'Could not find this dedicated server on the hosting provider (hostname lookup failed).'];
        }

        $rdns = (string) $params['rdns'];

        $response = $this->call($params['server'], 'POST', "/dedicated/{$dedicatedId}/reverse_dns", [
            'rdns' => $rdns,
        ]);

        return $this->toResult($response, 'Reverse DNS updated successfully.');
    }

    /** @param array<string, mixed> $params */
    private function lifecycleAction(array $params, string $method, string $pathTemplate): array
    {
        $dedicatedId = $this->resolveDedicatedId($params);

        if ($dedicatedId === null) {
            return ['success' => false, 'message' => 'Could not find this dedicated server on the hosting provider (hostname lookup failed).'];
        }

        $path = str_replace('{id}', (string) $dedicatedId, $pathTemplate);
        $response = $this->call($params['server'], $method, $path, $method === 'DELETE' || $method === 'GET' ? null : []);

        return $this->toResult($response);
    }

    /**
     * Lists the account's Dedicated services and finds the one whose hostname
     * matches this service's local username.
     *
     * @param array<string, mixed> $params
     */
    private function resolveDedicatedId(array $params): ?int
    {
        $hostname = (string) $params['username'];
        $response = $this->call($params['server'], 'GET', '/dedicated', null);
        $decoded = $this->decode($response);

        if (!$decoded['success'] || !is_array($decoded['data'])) {
            return null;
        }

        foreach ($decoded['data'] as $row) {
            if (is_array($row) && (string) ($row['dedicated_hostname'] ?? '') === $hostname) {
                return (int) $row['dedicated_id'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed>|null $body JSON-encoded when non-null; DELETE/GET calls pass null.
     * @return array{status: int, body: string}
     */
    private function call(array $server, string $method, string $path, ?array $body): array
    {
        $headers = ['X-API-KEY' => (string) ($server['api_token'] ?? ''), 'Accept' => 'application/json'];
        $encodedBody = null;

        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $encodedBody = json_encode($body);
        }

        return $this->http->request($method, self::BASE_URL . $path, $headers, $encodedBody);
    }

    /** @param array{status: int, body: string} $response */
    private function toResult(array $response, string $successMessage = 'OK'): array
    {
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message']];
        }

        return ['success' => true, 'message' => $decoded['message'] !== '' ? $decoded['message'] : $successMessage];
    }

    /**
     * @param array{status: int, body: string} $response
     * @return array{success: bool, message: string, data: mixed}
     */
    private function decode(array $response): array
    {
        if ($response['status'] === 0) {
            return ['success' => false, 'message' => 'Could not reach the hosting provider API.', 'data' => null];
        }

        $decoded = json_decode($response['body'], true);
        $ok = $response['status'] >= 200 && $response['status'] < 300;

        if (!is_array($decoded)) {
            return ['success' => $ok, 'message' => '', 'data' => null];
        }

        if (array_key_exists('success', $decoded)) {
            $ok = $ok && (bool) $decoded['success'];
        } elseif (array_key_exists('continue', $decoded)) {
            $ok = $ok && (bool) $decoded['continue'];
        }

        $message = (string) ($decoded['text'] ?? $decoded['message'] ?? $decoded['error'] ?? '');

        return ['success' => $ok, 'message' => $message, 'data' => $decoded];
    }
}
