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
 * The proxy can return the documented UAPI result envelope, a legacy
 * `cpanelresult` envelope (including a nested UAPI result), or a plain text
 * error object. All three are normalized here so tool callers get the real
 * panel error instead of a generic shape failure. Cron jobs, SSH key
 * management, and MySQL user listing still use cPanel API 2 because cPanel does
 * not expose those operations through UAPI.
 */
final class CpanelUapiClient
{
    private const DEFAULT_PORT = 2087;

    /** The modern (UAPI) API version. */
    private const API_VERSION = '3';

    /**
     * The legacy API 2 version, used only when a mapped UAPI list operation is
     * missing or the UAPI module cannot be loaded.
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

    /**
     * Old application builds used this non-existent UAPI name. Normalize it
     * before sending the request so already-deployed callers get the supported
     * SSL certificate listing function.
     *
     * @var array<string, array<string, string>>
     */
    private const UAPI_FUNCTION_ALIASES = [
        'ssl' => ['installed' => 'list_certs'],
    ];

    /**
     * Read-only API 2 replacements for UAPI list operations on older cPanel
     * servers. The API 2 function names are not always the same as their UAPI
     * counterparts (Cron::list_cronjobs is Cron::listcron in API 2).
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    private const API2_LIST_FALLBACKS = [
        'cron' => ['list_cronjobs' => ['Cron', 'listcron']],
        'ssl' => ['list_certs' => ['SSL', 'listcrts']],
        'mysql' => ['list_databases' => ['MysqlFE', 'listdbs']],
    ];

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
        $cpanelUser = trim($cpanelUser);

        if ($cpanelUser === '') {
            return [
                'success' => false,
                'message' => 'The service does not have a cPanel username assigned.',
                'data' => [],
                'api_version' => self::API_VERSION,
            ];
        }

        $func = self::uapiFunctionAlias($module, $func);
        $namedFallback = self::api2ListFallback($module, $func);
        $legacyTarget = $namedFallback ?? [$module, $func];
        $hasNamedFallback = $namedFallback !== null;
        $result = $this->callWithVersion($server, $cpanelUser, $module, $func, $params, self::API_VERSION);
        $moduleMissing = !$result['success'] && self::moduleUnavailable((string) $result['message']);
        $functionMissing = !$result['success']
            && $hasNamedFallback
            && self::functionUnavailable((string) $result['message']);

        if ($moduleMissing || $functionMissing) {
            [$legacyModule, $legacyFunc] = $legacyTarget;
            $legacy = $this->callWithVersion($server, $cpanelUser, $legacyModule, $legacyFunc, $params, self::LEGACY_API_VERSION);

            if ($legacy['success']) {
                return $legacy;
            }

            // Both calls failed. Preserve the original UAPI error and name the
            // exact API 2 target tried so a host administrator can diagnose the
            // missing module/function instead of seeing a misleading generic
            // "function not found" for a different API's name.
            $firstFailure = $moduleMissing
                ? 'The hosting panel does not have the ' . $module . ' module for UAPI'
                : 'The hosting panel could not run UAPI ' . $module . '::' . $func;

            return [
                'success' => false,
                'message' => $firstFailure . ' (' . $result['message'] . '), and API 2 '
                    . $legacyModule . '::' . $legacyFunc . ' failed too (' . $legacy['message'] . ').',
                'data' => [],
                'api_version' => self::LEGACY_API_VERSION,
            ];
        }

        return $result;
    }

    /**
     * Call a legacy cPanel API 2 function directly. Cron jobs and SSH key
     * management have no equivalent UAPI functions on cPanel, so those tools
     * must not first try a similarly named but nonexistent UAPI operation.
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $params
     * @return array{success: bool, message: string, data: mixed, api_version: string}
     */
    public function callApi2(array $server, string $cpanelUser, string $module, string $func, array $params = []): array
    {
        $cpanelUser = trim($cpanelUser);

        if ($cpanelUser === '') {
            return [
                'success' => false,
                'message' => 'The service does not have a cPanel username assigned.',
                'data' => [],
                'api_version' => self::LEGACY_API_VERSION,
            ];
        }

        return $this->callWithVersion($server, $cpanelUser, $module, $func, $params, self::LEGACY_API_VERSION);
    }

    /**
     * Secure POST variant for functions which carry passwords or private
     * material. Secret values belong in the form body, never a query string
     * that proxies and access logs may retain.
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $params
     * @return array{success: bool, message: string, data: mixed, api_version: string}
     */
    public function callPost(array $server, string $cpanelUser, string $module, string $func, array $params = []): array
    {
        return $this->callWithVersion($server, $cpanelUser, $module, $func, $params, self::API_VERSION, 'POST');
    }

    /**
     * Secure POST variant for API 2 mutations with sensitive or long values,
     * including SSH::genkey passphrases and cron command payloads.
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $params
     * @return array{success: bool, message: string, data: mixed, api_version: string}
     */
    public function callApi2Post(array $server, string $cpanelUser, string $module, string $func, array $params = []): array
    {
        return $this->callWithVersion($server, $cpanelUser, $module, $func, $params, self::LEGACY_API_VERSION, 'POST');
    }

    /**
     * One attempt at one API version and transport method. Split out so the
     * fallback path and secure POST mutations share the same request shape.
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
        string $apiVersion,
        string $method = 'GET'
    ): array {
        $cpanelUser = trim($cpanelUser);

        if ($cpanelUser === '') {
            return [
                'success' => false,
                'message' => 'The service does not have a cPanel username assigned.',
                'data' => [],
                'api_version' => $apiVersion,
            ];
        }

        $query = array_merge($params, [
            'api.version' => '1',
            'cpanel_jsonapi_user' => $cpanelUser,
            'cpanel_jsonapi_apiversion' => $apiVersion,
            'cpanel_jsonapi_module' => $module,
            'cpanel_jsonapi_func' => $func,
        ]);

        $decoded = $this->decodeUapi($this->request($server, 'cpanel', $query, $method));
        $decoded['api_version'] = $apiVersion;

        return self::addUsernameRejectionHint($decoded);
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

    private static function functionUnavailable(string $message): bool
    {
        foreach ([
            'function not found',
            'could not find function',
            'unknown function',
            'no such function',
            'function is not available',
            'function is unavailable',
        ] as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function uapiFunctionAlias(string $module, string $func): string
    {
        return self::UAPI_FUNCTION_ALIASES[strtolower($module)][strtolower($func)] ?? $func;
    }

    /** @return array{0: string, 1: string}|null */
    private static function api2ListFallback(string $module, string $func): ?array
    {
        return self::API2_LIST_FALLBACKS[strtolower($module)][strtolower($func)] ?? null;
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

    /** @param array{status: int, body: string, error?: string} $response */
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
            // Include the panel's exact text. Missing-module errors are sometimes
            // Perl compile output rather than JSON, and their wording is the only
            // useful signal for the API 2 fallback. An Imunify360 WebShield
            // challenge page, though, gets its own actionable message — it is
            // the one non-JSON failure an admin can fix from this side.
            if (self::webshieldChallenge($response['body'])) {
                return [
                    'success' => false,
                    'message' => self::webshieldMessage() . self::excerpt($response['body']),
                    'data' => [],
                ];
            }

            return [
                'success' => false,
                'message' => "Unexpected response (HTTP {$response['status']})." . self::excerpt($response['body']),
                'data' => [],
            ];
        }

        // Modern WHM-proxied UAPI: {"result": {"status", "errors", "data"}}.
        if (is_array($decoded['result'] ?? null) && array_key_exists('status', $decoded['result'])) {
            return $this->decodeUapiEnvelope($decoded['result'], $response['status']);
        }

        // Some WHM builds return the UAPI envelope without the result wrapper.
        if (array_key_exists('status', $decoded) && (array_key_exists('errors', $decoded) || array_key_exists('data', $decoded))) {
            return $this->decodeUapiEnvelope($decoded, $response['status']);
        }

        // Legacy cPanel API 1/2 wrapper. UAPI proxy responses on some builds
        // also wrap the UAPI result object inside cpanelresult.data[0].
        if (is_array($decoded['cpanelresult'] ?? null)) {
            return $this->decodeCpanelResult($decoded['cpanelresult'], $response['status']);
        }

        // Actual WHM proxy error shape, observed when the cPanel username is
        // rejected: {"data":{"reason":"...","result":"0"},"type":"text","error":"..."}.
        // Parse it as an API failure and preserve the panel's message.
        if (array_key_exists('data', $decoded) && (array_key_exists('error', $decoded) || array_key_exists('type', $decoded))) {
            return $this->decodeDirectPanelResult($decoded, $response['status']);
        }

        return [
            'success' => false,
            'message' => "Unrecognized UAPI response shape (HTTP {$response['status']})." . self::excerpt($response['body']),
            'data' => [],
        ];
    }

    /** @param array<string, mixed> $envelope */
    private function decodeUapiEnvelope(array $envelope, int $httpStatus): array
    {
        $ok = self::httpSucceeded($httpStatus) && (string) ($envelope['status'] ?? '') === '1';
        $message = $ok
            ? 'OK'
            : (self::panelMessage($envelope['errors'] ?? null)
                ?: self::panelMessage($envelope['messages'] ?? null)
                ?: (string) ($envelope['reason'] ?? "UAPI call failed (HTTP {$httpStatus})."));

        return [
            'success' => $ok,
            'message' => $message,
            'data' => $envelope['data'] ?? [],
        ];
    }

    /** @param array<string, mixed> $cpanelResult */
    private function decodeCpanelResult(array $cpanelResult, int $httpStatus): array
    {
        $data = $cpanelResult['data'] ?? [];

        // WHM's cpanel proxy may wrap the UAPI envelope in the first API2 data
        // row. Unwrap it so callers receive the UAPI payload, not its envelope.
        if (is_array($data) && self::hasUapiEnvelope($data)) {
            return $this->decodeUapiEnvelope($data, $httpStatus);
        }

        if (is_array($data) && self::isList($data) && count($data) === 1 && is_array($data[0]) && self::hasUapiEnvelope($data[0])) {
            return $this->decodeUapiEnvelope($data[0], $httpStatus);
        }

        $error = self::panelMessage($cpanelResult['error'] ?? null);
        $rowError = '';
        $rowFailed = false;

        foreach (self::isList($data) ? $data : [$data] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $nestedResult = is_array($row['result'] ?? null) ? $row['result'] : [];
            $flag = $nestedResult['status'] ?? $nestedResult['result'] ?? $row['result'] ?? null;

            // API 2 also uses `status` for descriptive values such as
            // "authorized". Treat it as a success flag only when it is
            // explicitly boolean-like; otherwise rely on the event result.
            if ($flag === null && array_key_exists('status', $row)) {
                $rowStatus = $row['status'];

                if (is_bool($rowStatus)) {
                    $flag = $rowStatus ? '1' : '0';
                } elseif (is_scalar($rowStatus)) {
                    $rowStatus = strtolower(trim((string) $rowStatus));

                    if (in_array($rowStatus, ['0', '1', 'true', 'false'], true)) {
                        $flag = in_array($rowStatus, ['1', 'true'], true) ? '1' : '0';
                    }
                }
            }

            if ($flag !== null && (string) $flag !== '1') {
                $rowFailed = true;
                $rowError = self::panelMessage(
                    $row['reason']
                    ?? $row['statusmsg']
                    ?? $nestedResult['reason']
                    ?? $nestedResult['statusmsg']
                    ?? null
                );
            }
        }

        $eventResult = $cpanelResult['event']['result'] ?? null;
        $eventFailed = $eventResult !== null && (string) $eventResult !== '1';
        $success = self::httpSucceeded($httpStatus) && $error === '' && !$rowFailed && !$eventFailed;
        $message = $success
            ? 'OK'
            : ($error ?: $rowError ?: "cPanel API call failed (HTTP {$httpStatus}).");

        return ['success' => $success, 'message' => $message, 'data' => $data];
    }

    /** @param array<string, mixed> $decoded */
    private function decodeDirectPanelResult(array $decoded, int $httpStatus): array
    {
        $data = $decoded['data'] ?? [];
        $flag = is_array($data) ? ($data['result'] ?? $data['status'] ?? null) : null;
        $error = self::panelMessage($decoded['error'] ?? null);
        $dataError = is_array($data) ? self::panelMessage($data['reason'] ?? $data['error'] ?? null) : '';
        $failed = ($flag !== null && (string) $flag !== '1') || $error !== '' || $dataError !== '';
        $success = self::httpSucceeded($httpStatus) && !$failed;
        $messageParts = array_values(array_unique(array_filter([$dataError, $error], static fn (string $message): bool => $message !== '')));
        $message = $success
            ? 'OK'
            : ($messageParts !== [] ? implode(' ', $messageParts) : "cPanel API call failed (HTTP {$httpStatus}).");

        return [
            'success' => $success,
            'message' => $message,
            'data' => is_array($data) && array_key_exists('data', $data) ? $data['data'] : $data,
        ];
    }

    /** @param array<string, mixed> $candidate */
    private static function hasUapiEnvelope(array $candidate): bool
    {
        return array_key_exists('status', $candidate) && array_key_exists('data', $candidate);
    }

    /** @param array<mixed> $value */
    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    private static function httpSucceeded(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    private static function panelMessage(mixed $value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }

        if (is_array($value)) {
            $messages = [];

            foreach ($value as $item) {
                $message = self::panelMessage($item);

                if ($message !== '') {
                    $messages[] = $message;
                }
            }

            return implode(' ', $messages);
        }

        return '';
    }

    /** @param array{success: bool, message: string, data: mixed, api_version?: string} $result */
    private static function addUsernameRejectionHint(array $result): array
    {
        if (!$result['success'] && stripos($result['message'], 'user parameter is invalid or was not supplied') !== false) {
            $result['message'] .= ' The WHM server rejected the cPanel username assigned to this service; an administrator must verify the service username and assigned server.';
        }

        return $result;
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
     * Detects the Imunify360 WebShield challenge ("Splash Screen" / CAPTCHA)
     * in a non-JSON response body. When a server runs Imunify360's
     * "cPanel account protection" (WEBSHIELD.panel_protection), WebShield
     * answers requests to the panel ports with a JavaScript challenge page —
     * a real browser passes it and gets a 24h cookie, but server-to-server
     * API automation can never run JavaScript, so EVERY tools/SSO call fails
     * with HTML instead of JSON. CloudLinux's documented resolution is to
     * whitelist the calling server's IP (or disable panel protection); older
     * WebShield builds (before 1.31.4) additionally loop on the challenge
     * even for real browsers.
     *
     * Returning an actionable message here turns "Unexpected response
     * (HTTP 200). Panel said: <!doctype html>…" — which admins cannot act on
     * — into the one command that fixes it.
     */
    private static function webshieldChallenge(string $body): bool
    {
        if (stripos($body, 'imunify') !== false) {
            return true;
        }

        foreach ([
            'please wait while your request is being verified',
            'webshield',
            'checking your browser',
            'ddos protection by',
        ] as $marker) {
            if (stripos($body, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The actionable message for a WebShield-challenged response. Includes
     * this server's outbound IP when it can be resolved from APP_URL — that
     * is the IP the WHM whitelist entry needs. DNS may fail or the billing
     * site may sit behind a proxy, so the wording covers both cases.
     */
    private static function webshieldMessage(): string
    {
        $ip = '';
        $siteUrl = trim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''));
        $host = $siteUrl !== '' ? (string) parse_url($siteUrl, PHP_URL_HOST) : '';

        if ($host !== '') {
            $resolved = gethostbyname($host);

            if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
                $ip = $resolved;
            }
        }

        $where = $ip !== ''
            ? "Whitelist this billing system's outbound IP ({$ip}) on the WHM server"
            : "Whitelist this billing system's outbound IP on the WHM server (resolve it from this site's direct address if it sits behind a proxy)";

        return 'The hosting server’s Imunify360 WebShield answered instead of the panel API. '
            . 'Server-to-server tools cannot pass its browser challenge, so every cPanel tool and quick-login fails until it is resolved. '
            . $where . ' — run: imunify360-agent whitelist ip add ' . ($ip !== '' ? $ip : '<THIS_SERVER_IP>')
            . ' (Imunify360 → Firewall → WhiteList). '
            . 'Also update WebShield on the server (builds before 1.31.4 loop on the challenge even for real browsers), '
            . 'or turn off cPanel account protection (WEBSHIELD.panel_protection) if the anti-bot check on panel logins is not wanted.';
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
            // WebShield challenge pages get the actionable message; everything
            // else keeps the generic shape error with the panel's own text.
            if (self::webshieldChallenge($response['body'])) {
                return [
                    'success' => false,
                    'message' => self::webshieldMessage() . self::excerpt($response['body']),
                    'data' => [],
                ];
            }

            return [
                'success' => false,
                'message' => "Unexpected response (HTTP {$response['status']})." . self::excerpt($response['body']),
                'data' => [],
            ];
        }

        if (isset($decoded['metadata'])) {
            $ok = self::httpSucceeded($response['status']) && (string) ($decoded['metadata']['result'] ?? '') === '1';

            return [
                'success' => $ok,
                'message' => $ok ? 'OK' : (self::panelMessage($decoded['metadata']['reason'] ?? null) ?: "WHM API error (HTTP {$response['status']})."),
                'data' => is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
            ];
        }

        if (is_array($decoded['cpanelresult'] ?? null)) {
            $cr = $decoded['cpanelresult'];
            $innerData = is_array($cr['data'] ?? null) ? $cr['data'] : [];
            $error = self::panelMessage($cr['error'] ?? null);
            $resultFlag = $innerData['result'] ?? $innerData['status'] ?? null;
            $eventResult = $cr['event']['result'] ?? null;
            $failed = $error !== ''
                || ($resultFlag !== null && (string) $resultFlag !== '1')
                || ($eventResult !== null && (string) $eventResult !== '1');
            $ok = self::httpSucceeded($response['status']) && !$failed;

            return [
                'success' => $ok,
                'message' => $ok ? 'OK' : ($error ?: self::panelMessage($innerData['reason'] ?? null) ?: "WHM API error (HTTP {$response['status']})."),
                'data' => $innerData,
            ];
        }

        if (array_key_exists('data', $decoded) && (array_key_exists('error', $decoded) || array_key_exists('type', $decoded))) {
            $result = $this->decodeDirectPanelResult($decoded, $response['status']);

            return self::addUsernameRejectionHint($result);
        }

        return ['success' => false, 'message' => "Unrecognized WHM response shape (HTTP {$response['status']})." . self::excerpt($response['body']), 'data' => []];
    }
}
