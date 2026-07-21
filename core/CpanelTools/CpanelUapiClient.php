<?php

declare(strict_types=1);

namespace CodeVault\CpanelTools;

use CodeVault\Provisioning\HttpClient;

/**
 * The client-area "cPanel Extended" tools (email/FTP/database/DNS
 * management, quick logins) all go through this: WHM API 1's `cpanel`
 * function proxies a UAPI call into one cPanel account, and
 * `create_user_session` (a plain WHM API 1 function, not proxied) mints an
 * SSO URL into that account. Both reuse the server's existing WHM API
 * token — no per-client cPanel password is ever needed, same as
 * CpanelProvisioningModule.
 *
 * NOT yet verified against a live server. The UAPI-proxy response shape
 * (`decodeUapi`) is built from cPanel's documented `{status, errors, data}`
 * UAPI envelope wrapped under a `result` key, which is the documented
 * behavior for WHM-proxied UAPI calls — but CpanelProvisioningModule's own
 * docblock notes a real WHM server surprised that implementation with an
 * undocumented legacy shape for one function, so this defensively also
 * accepts the raw (unwrapped) UAPI envelope and the legacy `cpanelresult`
 * shape rather than assuming the documented one is exactly right.
 */
final class CpanelUapiClient
{
    private const DEFAULT_PORT = 2087;

    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $params
     * @return array{success: bool, message: string, data: mixed}
     */
    public function call(array $server, string $cpanelUser, string $module, string $func, array $params = []): array
    {
        $query = array_merge($params, [
            'cpanel_jsonapi_user' => $cpanelUser,
            'cpanel_jsonapi_apiversion' => '3',
            'cpanel_jsonapi_module' => $module,
            'cpanel_jsonapi_func' => $func,
        ]);

        return $this->decodeUapi($this->request($server, 'cpanel', $query));
    }

    /**
     * A direct (non-proxied) WHM API 1 function call — used for
     * `create_user_session` (SSO). Same request shape as
     * CpanelProvisioningModule::call().
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $params
     * @return array{success: bool, message: string, data: array<string, mixed>}
     */
    public function callWhm(array $server, string $function, array $params = []): array
    {
        $query = array_merge($params, ['api.version' => '1']);

        return $this->decodeWhm($this->request($server, $function, $query));
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $query
     * @return array{status: int, body: string}
     */
    private function request(array $server, string $function, array $query): array
    {
        $port = $server['api_port'] ?? self::DEFAULT_PORT;
        $scheme = ($server['use_ssl'] ?? true) ? 'https' : 'http';

        $url = "{$scheme}://{$server['hostname']}:{$port}/json-api/{$function}?" . http_build_query($query);

        return $this->http->request('GET', $url, [
            'Authorization' => "whm {$server['api_username']}:{$server['api_token']}",
        ]);
    }

    /** @param array{status: int, body: string} $response */
    private function decodeUapi(array $response): array
    {
        if ($response['status'] === 0) {
            return ['success' => false, 'message' => 'Could not reach the WHM server.', 'data' => []];
        }

        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded)) {
            return ['success' => false, 'message' => "Unexpected response (HTTP {$response['status']}).", 'data' => []];
        }

        // Documented shape: the raw UAPI envelope ({status, errors, data, ...})
        // wrapped under "result". Fall back to treating the top level as the
        // envelope itself in case a given WHM build doesn't wrap it.
        $envelope = is_array($decoded['result'] ?? null) ? $decoded['result'] : $decoded;

        if (array_key_exists('status', $envelope)) {
            $ok = $response['status'] === 200 && (int) $envelope['status'] === 1;
            $errors = $envelope['errors'] ?? null;
            $message = $ok
                ? 'OK'
                : (is_array($errors) ? implode(' ', $errors) : (string) ($errors ?? "UAPI call failed (HTTP {$response['status']})."));

            return ['success' => $ok, 'message' => $message, 'data' => $envelope['data'] ?? []];
        }

        if (isset($decoded['cpanelresult'])) {
            $cr = $decoded['cpanelresult'];
            $hasError = isset($cr['error']);

            return [
                'success' => !$hasError,
                'message' => $hasError ? (string) $cr['error'] : 'OK',
                'data' => $cr['data'] ?? [],
            ];
        }

        return ['success' => false, 'message' => "Unrecognized UAPI response shape (HTTP {$response['status']}).", 'data' => []];
    }

    /**
     * Same normalization CpanelProvisioningModule::decode() performs
     * (duplicated rather than shared — that class is `final` and this is a
     * separate bounded context, matching how CyberPanelProvisioningModule
     * also owns its own decode logic rather than reusing cPanel's).
     *
     * @param array{status: int, body: string} $response
     */
    private function decodeWhm(array $response): array
    {
        if ($response['status'] === 0) {
            return ['success' => false, 'message' => 'Could not reach the WHM server.', 'data' => []];
        }

        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded)) {
            return ['success' => false, 'message' => "Unexpected response (HTTP {$response['status']}).", 'data' => []];
        }

        if (isset($decoded['metadata'])) {
            $ok = $response['status'] === 200 && ($decoded['metadata']['result'] ?? null) === 1;

            return [
                'success' => $ok,
                'message' => $ok ? 'OK' : (string) ($decoded['metadata']['reason'] ?? "WHM API error (HTTP {$response['status']})."),
                'data' => is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
            ];
        }

        if (isset($decoded['cpanelresult'])) {
            $cr = $decoded['cpanelresult'];
            $innerData = is_array($cr['data'] ?? null) ? $cr['data'] : [];
            $hasError = isset($cr['error']);
            $resultFlag = $innerData['result'] ?? null;
            $ok = $response['status'] === 200 && !$hasError && ($resultFlag === null || (string) $resultFlag === '1');

            return [
                'success' => $ok,
                'message' => (string) ($cr['error'] ?? ($innerData['reason'] ?? ($ok ? 'OK' : "WHM API error (HTTP {$response['status']})."))),
                'data' => $innerData,
            ];
        }

        return ['success' => false, 'message' => "Unrecognized WHM response shape (HTTP {$response['status']}).", 'data' => []];
    }
}
