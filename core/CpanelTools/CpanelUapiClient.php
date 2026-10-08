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

    /** The modern (UAPI) API version. */
    private const API_VERSION = '3';

    /**
     * The legacy API 2 version, tried only when the UAPI module is missing.
     *
     * This exists because of a real server. Asking for
     * `AddonDomain::addaddondomain` over UAPI came back with:
     *
     *   Failed to load module "AddonDomain": ... Can't locate
     *   Cpanel/API/AddonDomain.pm in @INC
     *
     * i.e. the panel answered, and simply does not have that UAPI module
     * installed. The same operations have existed for years as API 2 modules
     * (`Cpanel/API2/...`), which older builds ship and newer ones kept for
     * compatibility, so the fallback is worth trying before giving up and
     * making an administrator do it by hand.
     */
    private const LEGACY_API_VERSION = '2';

    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $params
     * @return array{success: bool, message: string, data: mixed, api_version: string}
     */
    public function call(array $server, string $cpanelUser, string $module, string $func, array $params = []): array
    {
        $result = $this->callWithVersion($server, $cpanelUser, $module, $func, $params, self::API_VERSION);

        if (!$result['success'] && self::moduleUnavailable((string) $result['message'])) {
            $legacy = $this->callWithVersion($server, $cpanelUser, $module, $func, $params, self::LEGACY_API_VERSION);

            if ($legacy['success']) {
                return $legacy;
            }

            // BOTH failed, and the two messages name different things: the first
            // says which module file is missing, the second says what the legacy
            // route made of the same request. Report both rather than only the
            // last one, because the first is the one an administrator can act on.
            return [
                'success' => false,
                'message' => 'The hosting panel does not have the ' . $module . ' module for UAPI ('
                    . $result['message'] . '), and the legacy API 2 equivalent failed too ('
                    . $legacy['message'] . ').',
                'data' => [],
                'api_version' => self::LEGACY_API_VERSION,
            ];
        }

        return $result;
    }

    /**
     * Secure POST variant for UAPI functions which carry private material. In
     * particular SSL::install_ssl must never put a private key in a URL/query
     * string (which can be retained by proxy and access logs).
     *
     * @param array<string,mixed> $server
     * @param array<string,mixed> $params
     * @return array{success:bool,message:string,data:mixed,api_version:string}
     */
    public function callPost(array $server, string $cpanelUser, string $module, string $func, array $params = []): array
    {
        $query=array_merge($params,[
            'cpanel_jsonapi_user'=>$cpanelUser,
            'cpanel_jsonapi_apiversion'=>self::API_VERSION,
            'cpanel_jsonapi_module'=>$module,
            'cpanel_jsonapi_func'=>$func,
        ]);
        $decoded=$this->decodeUapi($this->request($server,'cpanel',$query,'POST'));
        $decoded['api_version']=self::API_VERSION;
        return $decoded;
    }

    /**
     * One attempt at one API version. Split out so the fallback above cannot
     * drift from the primary path — they differ in exactly one parameter.
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $params
     * @return array{success: bool, message: string, data: mixed, api_version: string}
     */
    private function callWithVersion(
        array $server,
        string $cpanelUser,
        string $module,
        string $func,
        array $params,
        string $apiVersion
    ): array {
        $query = array_merge($params, [
            'cpanel_jsonapi_user' => $cpanelUser,
            'cpanel_jsonapi_apiversion' => $apiVersion,
            'cpanel_jsonapi_module' => $module,
            'cpanel_jsonapi_func' => $func,
        ]);

        $decoded = $this->decodeUapi($this->request($server, 'cpanel', $query));
        $decoded['api_version'] = $apiVersion;

        return $decoded;
    }

    /**
     * Does this message mean "the panel does not have that module"? Matched on
     * the panel's own wording, which is all we get: cPanel reports a missing
     * UAPI module as a Perl compile failure, not as a structured error.
     */
    private static function moduleUnavailable(string $message): bool
    {
        foreach (['failed to load module', "can't locate", 'begin failed', 'module not found'] as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
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
    private function request(array $server, string $function, array $query, string $method = 'GET'): array
    {
        $port = $server['api_port'] ?? self::DEFAULT_PORT;
        $scheme = ($server['use_ssl'] ?? true) ? 'https' : 'http';
        $url = "{$scheme}://{$server['hostname']}:{$port}/json-api/{$function}";
        $body = null;
        $headers = [];
        if ($method === 'POST') {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            $body = http_build_query($query);
        } else {
            $url .= '?' . http_build_query($query);
        }

        $token = $server['api_token'] ?? '';
        $username = $server['api_username'] ?? '';
        $isToken = preg_match('/^[a-zA-Z0-9]{30,64}$/', $token);
        $authHeader = $isToken 
            ? "whm {$username}:{$token}" 
            : "Basic " . base64_encode("{$username}:{$token}");

        $headers['Authorization'] = $authHeader;
        return $this->http->request($method, $url, $headers, $body);
    }

    /** @param array{status: int, body: string} $response */
    private function decodeUapi(array $response): array
    {
        if ($response['status'] === 0) {
            $msg = 'Could not reach the WHM server.';
            if (!empty($response['error'])) {
                $msg .= ' (' . $response['error'] . ')';
            }
            return ['success' => false, 'message' => $msg, 'data' => []];
        }

        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded)) {
            // Include what the panel actually sent. "Unexpected response" alone is
            // useless precisely when it matters: cPanel reports a missing module
            // as a Perl compile failure, and if that arrives outside a JSON
            // envelope the wording is the only clue there is — and it is also
            // what makes moduleUnavailable() fire, so the API 2 retry happens.
            return [
                'success' => false,
                'message' => "Unexpected response (HTTP {$response['status']})." . self::excerpt($response['body']),
                'data' => [],
            ];
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

        return ['success' => false, 'message' => "Unrecognized UAPI response shape (HTTP {$response['status']})." . self::excerpt($response['body']), 'data' => []];
    }

    /**
     * A short, single-line excerpt of a raw reply, for error messages.
     *
     * Truncated and newline-collapsed because these land in a flash message, an
     * activity log row and a database column — a 40-line Perl stack trace pasted
     * into all three helps nobody.
     */
    private static function excerpt(string $body): string
    {
        $body = trim((string) preg_replace('~\s+~', ' ', $body));

        if ($body === '') {
            return '';
        }

        return ' Panel said: ' . (strlen($body) > 300 ? substr($body, 0, 300) . '…' : $body);
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
            $msg = 'Could not reach the WHM server.';
            if (!empty($response['error'])) {
                $msg .= ' (' . $response['error'] . ')';
            }
            return ['success' => false, 'message' => $msg, 'data' => []];
        }

        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded)) {
            return [
                'success' => false,
                'message' => "Unexpected response (HTTP {$response['status']})." . self::excerpt($response['body']),
                'data' => [],
            ];
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
