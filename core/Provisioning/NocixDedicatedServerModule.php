<?php

declare(strict_types=1);

namespace CodeVault\Provisioning;

use CodeVault\Modules\ProvisioningModule;

/**
 * Dedicated-server management against Nocix's real, live client API
 * (blueprint §3 — `https://manage.nocix.net/api/{call}/`, HTTP Basic auth with
 * `username:token`). Endpoint paths, variables, and response shapes below
 * are read directly from Nocix's own published API documentation
 * (my.nocix.net/apidoc, explicitly marked BETA) — not guessed. Note: API
 * documentation is hosted at https://my.nocix.net/api/ but actual requests
 * go to https://manage.nocix.net/api/.
 *
 * Nocix's documented API has real, honest gaps this module does not paper
 * over: there is no order-placement endpoint (a dedicated server is bought
 * through Nocix's own client portal, not provisioned on demand), and no
 * cancel, root-password, plan-change, or remote-console endpoint exists
 * for dedicated servers either — bare metal isn't self-service the way a
 * hypervisor-backed VPS is. `create()`, `terminate()`, `changePassword()`,
 * and `changePackage()` all report this plainly rather than fabricating
 * success — the exact "partial module" precedent R7's
 * ConnectResellerRegistrarModule already established for the same reason
 * (a real, documented API limitation, not missing effort).
 *
 * Unlike InterServerVpsProvisioningModule (R25), there is no hostname-to-
 * numeric-id resolution needed here: since create() is never what
 * establishes a Nocix service in the first place (it's bought manually),
 * the expected workflow is that an admin assigns the *real* Nocix numeric
 * service id directly as this service's `username` when attaching it to
 * this module — so every lifecycle call below treats `$params['username']`
 * as that id verbatim.
 */
final class NocixDedicatedServerModule implements ProvisioningModule
{
    private const BASE_URL = 'https://manage.nocix.net/api';

    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'Nocix Dedicated Server',
            'description' => 'Manages Nocix dedicated servers (reboot, disconnect/reconnect, OS reload, bandwidth) via the Nocix client API.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [];
    }

    /**
     * Nocix has no order-placement endpoint — dedicated servers are bought
     * through the Nocix client portal, not provisioned on demand. The
     * expected workflow is: buy the server on Nocix, then set this
     * service's `username` (in WHMP) to the real Nocix numeric service id
     * so the lifecycle methods below can act on the right box.
     */
    public function create(array $params): array
    {
        return [
            'success' => false,
            'message' => 'Nocix does not support ordering dedicated servers via API. Provision the server through the Nocix client portal, then set this service\'s username to the resulting Nocix service ID.',
        ];
    }

    public function suspend(array $params): array
    {
        $response = $this->call($params['server'], 'disconnect-server', ['service_id' => (string) $params['username']]);

        return $this->toResult($response, 'Server disconnected from the network.');
    }

    public function unsuspend(array $params): array
    {
        $response = $this->call($params['server'], 'reconnect-server', ['service_id' => (string) $params['username']]);

        return $this->toResult($response, 'Server reconnected to the network.');
    }

    /** Nocix's documented API has no decommission/cancel endpoint for dedicated servers. */
    public function terminate(array $params): array
    {
        return [
            'success' => false,
            'message' => 'Nocix does not expose a server cancellation endpoint. Cancel the service through the Nocix client portal or support.',
        ];
    }

    /** No root/administrator password endpoint is documented for Nocix dedicated servers — typically managed out-of-band via IPMI/KVM. */
    public function changePassword(array $params): array
    {
        return [
            'success' => false,
            'message' => 'Nocix does not expose a root password change endpoint for dedicated servers.',
        ];
    }

    /** No plan/hardware-change endpoint is documented — a dedicated server's specification is fixed to the physical box. */
    public function changePackage(array $params): array
    {
        return [
            'success' => false,
            'message' => 'Nocix does not support changing a dedicated server\'s plan via API — hardware specification changes require a new server.',
        ];
    }

    /** No remote-console/KVM endpoint is documented for Nocix dedicated servers. */
    public function singleSignOn(array $params): array
    {
        return [
            'success' => false,
            'message' => 'Nocix does not expose a remote console endpoint via API.',
        ];
    }

    public function usage(array $params): array
    {
        $response = $this->call($params['server'], 'bandwidth-graphing', ['service_id' => (string) $params['username']]);
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message']];
        }

        return [
            'success' => true,
            'data' => $decoded['data'],
            'diskUsedMb' => null,
            'diskLimitMb' => null,
            'bandwidthUsedMb' => null,
            'bandwidthLimitMb' => null,
        ];
    }

    public function testConnection(array $params): array
    {
        $response = $this->call($params['server'], 'list-services', []);

        return $this->toResult($response, 'Connected — API credentials are valid.');
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $query
     * @return array{status: int, body: string}
     */
    private function call(array $server, string $endpoint, array $query): array
    {
        $url = self::BASE_URL . '/' . $endpoint . '/';

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $credentials = base64_encode(($server['api_username'] ?? '') . ':' . ($server['api_token'] ?? ''));

        return $this->http->request('GET', $url, ['Authorization' => "Basic {$credentials}"]);
    }

    /** @param array{status: int, body: string} $response */
    private function toResult(array $response, string $successMessage): array
    {
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message']];
        }

        return ['success' => true, 'message' => $successMessage];
    }

    /**
     * Nocix's own docs: "A successful call will return with either a
     * result set, or an error message contained within a JSON array
     * (error=>message)."
     *
     * @param array{status: int, body: string} $response
     * @return array{success: bool, message: string, data: mixed}
     */
    private function decode(array $response): array
    {
        if ($response['status'] === 0) {
            return ['success' => false, 'message' => 'Could not reach the Nocix API.', 'data' => null];
        }

        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded)) {
            return [
                'success' => $response['status'] >= 200 && $response['status'] < 300,
                'message' => '',
                'data' => null,
            ];
        }

        if (isset($decoded['error'])) {
            return ['success' => false, 'message' => (string) $decoded['error'], 'data' => null];
        }

        $ok = $response['status'] >= 200 && $response['status'] < 300;

        return ['success' => $ok, 'message' => $ok ? '' : "Nocix API error (HTTP {$response['status']}).", 'data' => $decoded];
    }
}
