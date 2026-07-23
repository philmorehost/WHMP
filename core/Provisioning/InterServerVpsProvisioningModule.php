<?php

declare(strict_types=1);

namespace CodeVault\Provisioning;

use CodeVault\Modules\ProvisioningModule;

/**
 * VPS provisioning against InterServer's real, live Management API
 * (blueprint §3 — `https://my.interserver.net/apiv2`, `X-API-KEY` header
 * auth). Endpoint paths, request bodies, and response shapes below were
 * read directly from InterServer's own published API reference
 * (my.interserver.net/api-docs, v0.9.0) during development — not guessed.
 *
 * Architectural note: `ProvisioningService` (the orchestration engine)
 * threads exactly one identifier — `services.username` — through every
 * lifecycle call after create(); it has no separate "remote id" column.
 * cPanel's username IS its account identifier, so that fits directly, but
 * InterServer's VPS lifecycle endpoints are keyed by a numeric `vps_id`
 * only InterServer assigns (returned from the order call, not chosen by
 * us). Rather than widen the core orchestration engine's schema for one
 * module — real regression risk to every other already-verified module —
 * this module stores the locally-generated username as the VPS's
 * `hostname` at order time, then resolves `vps_id` by listing the
 * account's VPS services and matching on that hostname whenever a later
 * lifecycle call needs the numeric id. One extra read call per action,
 * paid only by this module, in exchange for zero changes to shared code.
 */
final class InterServerVpsProvisioningModule implements ProvisioningModule
{
    private const BASE_URL = 'https://my.interserver.net/apiv2';

    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'InterServer VPS',
            'description' => 'Orders and manages KVM/HyperV VPS instances via the InterServer Management API.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [
            'default_platform' => ['type' => 'text', 'label' => 'Default VPS Platform', 'default' => 'kvm'],
            'default_slices' => ['type' => 'text', 'label' => 'Default Slice Count', 'default' => '1'],
            'default_location' => ['type' => 'text', 'label' => 'Default Location ID', 'default' => '1'],
        ];
    }

    /**
     * vpsPlatform is one of the literal enum values kvm|hyperv|kvmstorage
     * (lowercase — confirmed from the live schema, not the same casing as
     * the prose "KVM"/"HyperV" service-type names shown elsewhere in the
     * same docs). osVersion is a template identifier string (e.g.
     * "ubuntu24"), not a bare version number.
     */
    public function create(array $params): array
    {
        $server = $params['server'];
        $hostname = (string) $params['username'];

        $body = [
            'osDistro' => (string) ($params['osDistro'] ?? 'ubuntu'),
            'osVersion' => (string) ($params['osVersion'] ?? 'ubuntu24'),
            'vpsPlatform' => (string) ($params['vpsPlatform'] ?? ($server['default_platform'] ?? 'kvm')),
            'controlpanel' => (string) ($params['controlpanel'] ?? 'none'),
            'slices' => (int) ($params['slices'] ?? ($server['default_slices'] ?? 1)),
            'period' => (int) ($params['period'] ?? 1),
            'location' => (int) ($params['location'] ?? ($server['default_location'] ?? 1)),
            'hostname' => $hostname,
            'rootpass' => (string) ($params['password'] ?? bin2hex(random_bytes(8))),
            'coupon' => (string) ($params['coupon'] ?? ''),
            'comment' => (string) ($params['comment'] ?? ''),
        ];

        $response = $this->call($server, 'POST', '/vps/order', $body);
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message']];
        }

        // InterServer's own docs are inconsistent about the casing here —
        // the prose "Returned fields" section says `serviceid`, but the
        // formal response schema and worked example both use `serviceId`.
        // Check both rather than trust one.
        $data = is_array($decoded['data']) ? $decoded['data'] : [];
        $serviceId = $data['serviceId'] ?? $data['serviceid'] ?? null;

        return [
            'success' => true,
            'message' => $serviceId !== null
                ? "VPS order placed (InterServer service #{$serviceId})."
                : 'VPS order placed.',
        ];
    }

    public function suspend(array $params): array
    {
        return $this->lifecycleAction($params, 'GET', '/vps/{id}/stop');
    }

    public function unsuspend(array $params): array
    {
        return $this->lifecycleAction($params, 'GET', '/vps/{id}/start');
    }

    public function terminate(array $params): array
    {
        return $this->lifecycleAction($params, 'DELETE', '/vps/{id}');
    }

    public function changePassword(array $params): array
    {
        $vpsId = $this->resolveVpsId($params);

        if ($vpsId === null) {
            return ['success' => false, 'message' => 'Could not find this VPS on InterServer (hostname lookup failed).'];
        }

        $response = $this->call($params['server'], 'POST', "/vps/{$vpsId}/change_root_password", [
            'password' => (string) $params['password'],
        ]);

        return $this->toResult($response);
    }

    public function changePackage(array $params): array
    {
        $vpsId = $this->resolveVpsId($params);

        if ($vpsId === null) {
            return ['success' => false, 'message' => 'Could not find this VPS on InterServer (hostname lookup failed).'];
        }

        $slices = (int) ($params['package'] ?? $params['slices'] ?? 0);

        if ($slices <= 0) {
            return ['success' => false, 'message' => 'A target slice count is required to change the VPS package.'];
        }

        $response = $this->call($params['server'], 'POST', "/vps/{$vpsId}/slices", ['slices' => $slices]);

        return $this->toResult($response);
    }

    /**
     * InterServer has no cPanel-style "create_user_session" call that hands
     * back a one-click browser URL — its own docs describe
     * getVpsSetupVnc's response only as "Object with VNC connection info
     * (IP, port, credentials when provisioned)" with no fixed field names,
     * and note it's "a stub for some platforms." Rather than guess a shape
     * that isn't documented, this reads whatever connection info is
     * available (GET, side-effect-free) and — when present — hands back a
     * `vnc://host:port` URI, a real scheme VNC clients can be launched
     * from. If nothing is provisioned yet, it reports that plainly instead
     * of fabricating success; postVpsSetupVnc (which needs the caller's own
     * IP to whitelist) is intentionally not called automatically here.
     */
    public function singleSignOn(array $params): array
    {
        $vpsId = $this->resolveVpsId($params);

        if ($vpsId === null) {
            return ['success' => false, 'message' => 'Could not find this VPS on InterServer (hostname lookup failed).'];
        }

        $response = $this->call($params['server'], 'GET', "/vps/{$vpsId}/setup_vnc", null);
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message']];
        }

        $data = is_array($decoded['data']) ? $decoded['data'] : [];
        $ip = $data['ip'] ?? $data['vnc_ip'] ?? $data['vnc'] ?? null;
        $port = $data['port'] ?? $data['vnc_port'] ?? null;

        if ($ip === null) {
            return [
                'success' => false,
                'message' => 'No VNC console is provisioned for this VPS yet — provision one from the InterServer dashboard first.',
            ];
        }

        $url = $port !== null ? "vnc://{$ip}:{$port}" : "vnc://{$ip}";

        return ['success' => true, 'url' => $url, 'message' => 'VNC console connection info retrieved.'];
    }

    public function usage(array $params): array
    {
        $vpsId = $this->resolveVpsId($params);

        if ($vpsId === null) {
            return ['success' => false, 'message' => 'Could not find this VPS on InterServer (hostname lookup failed).'];
        }

        $response = $this->call($params['server'], 'GET', "/vps/{$vpsId}/traffic_usage", null);
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
        $response = $this->call($params['server'], 'GET', '/vps', null);

        return $this->toResult($response, 'Connected — API key is valid.');
    }

    public function reinstall(array $params): array
    {
        $vpsId = $this->resolveVpsId($params);

        if ($vpsId === null) {
            return ['success' => false, 'message' => 'Could not find this VPS on InterServer (hostname lookup failed).'];
        }

        $osVersion = (string) ($params['osVersion'] ?? 'ubuntu24');

        $response = $this->call($params['server'], 'POST', "/vps/{$vpsId}/reinstall", [
            'osVersion' => $osVersion,
        ]);

        return $this->toResult($response, 'VPS OS reinstallation has been queued.');
    }

    public function setReverseDns(array $params): array
    {
        $vpsId = $this->resolveVpsId($params);

        if ($vpsId === null) {
            return ['success' => false, 'message' => 'Could not find this VPS on InterServer (hostname lookup failed).'];
        }

        $rdns = (string) $params['rdns'];

        $response = $this->call($params['server'], 'POST', "/vps/{$vpsId}/reverse_dns", [
            'rdns' => $rdns,
        ]);

        return $this->toResult($response, 'Reverse DNS updated successfully.');
    }

    /** @param array<string, mixed> $params */
    private function lifecycleAction(array $params, string $method, string $pathTemplate): array
    {
        $vpsId = $this->resolveVpsId($params);

        if ($vpsId === null) {
            return ['success' => false, 'message' => 'Could not find this VPS on InterServer (hostname lookup failed).'];
        }

        $path = str_replace('{id}', (string) $vpsId, $pathTemplate);
        $response = $this->call($params['server'], $method, $path, $method === 'DELETE' || $method === 'GET' ? null : []);

        return $this->toResult($response);
    }

    /**
     * Lists the account's VPS services and finds the one whose hostname
     * matches this service's local username — see class docblock.
     *
     * @param array<string, mixed> $params
     */
    private function resolveVpsId(array $params): ?int
    {
        $hostname = (string) $params['username'];
        $response = $this->call($params['server'], 'GET', '/vps', null);
        $decoded = $this->decode($response);

        if (!$decoded['success'] || !is_array($decoded['data'])) {
            return null;
        }

        foreach ($decoded['data'] as $row) {
            if (is_array($row) && (string) ($row['vps_hostname'] ?? '') === $hostname) {
                return (int) $row['vps_id'];
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
            return ['success' => false, 'message' => 'Could not reach the InterServer API.', 'data' => null];
        }

        $decoded = json_decode($response['body'], true);
        $ok = $response['status'] >= 200 && $response['status'] < 300;

        if (!is_array($decoded)) {
            return ['success' => $ok, 'message' => '', 'data' => null];
        }

        // VPSCancel documents a `success` flag; the /vps/order (addVps)
        // docs are internally inconsistent — the prose section names a
        // `success` field, but the formal schema and worked example both
        // use `continue` instead with no `success` key at all. Most
        // lifecycle actions (start/stop/reinstall/...) have neither and
        // simply return {text, queueId} on any 2xx. Check both rather than
        // assume one is authoritative.
        if (array_key_exists('success', $decoded)) {
            $ok = $ok && (bool) $decoded['success'];
        } elseif (array_key_exists('continue', $decoded)) {
            $ok = $ok && (bool) $decoded['continue'];
        }

        $message = (string) ($decoded['text'] ?? $decoded['message'] ?? $decoded['error'] ?? '');

        return ['success' => $ok, 'message' => $message, 'data' => $decoded];
    }
}
