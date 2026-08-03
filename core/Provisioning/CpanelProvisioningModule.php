<?php

declare(strict_types=1);

namespace CodeVault\Provisioning;

use CodeVault\Modules\ProvisioningModule;

/**
 * cPanel/WHM server module, built against WHM API 1 (`/json-api/...`,
 * `Authorization: whm user:token` auth).
 *
 * Verified live end-to-end against a real WHM server: testConnection,
 * create, usage, suspend, unsuspend, singleSignOn, and terminate all
 * confirmed working (terminate confirmed by a follow-up usage() call
 * correctly reporting "Account does not exist"). Two things this live
 * run caught: (1) `version` — and likely a few other older functions —
 * responds in the legacy `{"cpanelresult": {"data": {...}, "error": "..."}}`
 * shape rather than the modern `{"metadata": {...}, "data": {...}}` shape
 * account-management functions use, even under /json-api/?api.version=1;
 * decode() normalizes both. (2) a real `createacct` call (DNS zone, mail,
 * AutoSSL) can comfortably exceed 60 seconds — the bound HttpClient uses a
 * 120s timeout, not a short default, because of this.
 *
 * NOT yet verified: the CyberPanel module (§4.4 doc note) and this
 * module's `changePassword`/`changePackage` paths specifically (the live
 * run didn't exercise those two).
 */
final class CpanelProvisioningModule implements ProvisioningModule
{
    private const DEFAULT_PORT = 2087;

    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'cPanel / WHM',
            'description' => 'Creates and manages cPanel accounts via WHM API 1.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [
            'default_package' => ['type' => 'text', 'label' => 'Default WHM Package', 'default' => 'default'],
        ];
    }

    public function create(array $params): array
    {
        $server = $params['server'];
        $username = (string) $params['username'];
        $plan = !empty($params['whm_package_name']) ? (string) $params['whm_package_name'] : ($server['default_package'] ?? 'default');

        $response = $this->call($server, 'createacct', [
            'username' => $username,
            'domain' => $params['domain'] ?? "{$username}.example.invalid",
            'plan' => $plan,
            'password' => $params['password'] ?? bin2hex(random_bytes(12)),
        ]);

        return $this->toResult($response);
    }

    public function suspend(array $params): array
    {
        $response = $this->call($params['server'], 'suspendacct', [
            'user' => (string) $params['username'],
            'reason' => $params['reason'] ?? 'Suspended via CodeVault',
        ]);

        return $this->toResult($response);
    }

    public function unsuspend(array $params): array
    {
        $response = $this->call($params['server'], 'unsuspendacct', ['user' => (string) $params['username']]);

        return $this->toResult($response);
    }

    public function terminate(array $params): array
    {
        $response = $this->call($params['server'], 'removeacct', ['user' => (string) $params['username']]);

        return $this->toResult($response);
    }

    public function changePassword(array $params): array
    {
        $response = $this->call($params['server'], 'passwd', [
            'user' => (string) $params['username'],
            'password' => (string) $params['password'],
        ]);

        return $this->toResult($response);
    }

    public function changePackage(array $params): array
    {
        $response = $this->call($params['server'], 'changepackage', [
            'user' => (string) $params['username'],
            'pkg' => (string) ($params['package'] ?? 'default'),
        ]);

        return $this->toResult($response);
    }

    /**
     * Renames the account's primary domain via WHM's `modifyacct`. Optional
     * (not on ProvisioningModule) since this is a cPanel-specific concept —
     * dispatched through ProvisioningService::changeDomain()'s method_exists
     * check, same as changePassword()/changePackage() would be if a VPS
     * module had no equivalent.
     */
    public function changeDomain(array $params): array
    {
        $response = $this->call($params['server'], 'modifyacct', [
            'user' => (string) $params['username'],
            'domain' => (string) $params['domain'],
        ]);

        return $this->toResult($response);
    }

    public function singleSignOn(array $params): array
    {
        $response = $this->call($params['server'], 'create_user_session', [
            'user' => (string) $params['username'],
            'service' => 'cpaneld',
        ]);

        $decoded = $this->decode($response);
        $url = $decoded['data']['url'] ?? null;

        if (!$decoded['success'] || $url === null) {
            return ['success' => false, 'message' => $decoded['reason'] ?? 'SSO request failed.'];
        }

        return ['success' => true, 'url' => $url, 'message' => 'SSO link generated.'];
    }

    public function usage(array $params): array
    {
        $response = $this->call($params['server'], 'accountsummary', ['user' => (string) $params['username']]);
        $decoded = $this->decode($response);
        $acct = $decoded['data']['acct'][0] ?? null;

        if (!$decoded['success'] || $acct === null) {
            return ['success' => false, 'message' => $decoded['reason'] ?? 'Usage lookup failed.'];
        }

        return [
            'success' => true,
            'diskUsedMb' => (float) ($acct['diskused'] ?? 0),
            'diskLimitMb' => (float) ($acct['disklimit'] ?? 0),
            'bandwidthUsedMb' => (float) ($acct['bandwidthused'] ?? 0),
            'bandwidthLimitMb' => (float) ($acct['bandwidthlimit'] ?? 0),
        ];
    }

    public function testConnection(array $params): array
    {
        $response = $this->call($params['server'], 'version', []);

        return $this->toResult($response, 'Connected.');
    }

    /** @param array<string, mixed> $server */
    private function call(array $server, string $function, array $query): array
    {
        $port = $server['api_port'] ?? self::DEFAULT_PORT;
        $scheme = ($server['use_ssl'] ?? true) ? 'https' : 'http';
        $query['api.version'] = '1';

        $url = "{$scheme}://{$server['hostname']}:{$port}/json-api/{$function}?" . http_build_query($query);

        $token = $server['api_token'] ?? '';
        $username = $server['api_username'] ?? '';
        // WHM API tokens are alphanumeric and typically 32 chars. 
        // If it contains special characters or is short, assume it's a password.
        $isToken = preg_match('/^[a-zA-Z0-9]{30,64}$/', $token);
        $authHeader = $isToken 
            ? "whm {$username}:{$token}" 
            : "Basic " . base64_encode("{$username}:{$token}");

        return $this->http->request('GET', $url, [
            'Authorization' => $authHeader,
        ]);
    }

    /** @param array{status: int, body: string} $response */
    private function toResult(array $response, string $successMessage = 'OK'): array
    {
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['reason']];
        }

        return ['success' => true, 'message' => $successMessage];
    }

    /**
     * Normalizes both WHM response shapes into one internal format so
     * every caller above can ignore which one it got:
     *   - modern:  {"metadata": {"result": 1, "reason": "..."}, "data": {...}}
     *   - legacy:  {"cpanelresult": {"data": {"result": "1"?, "reason"?: "..."}, "error"?: "...", ...}}
     * (`version` and a handful of other older functions use the legacy
     * shape even under /json-api/?api.version=1 — confirmed live.)
     *
     * @param array{status: int, body: string} $response
     * @return array{success: bool, reason: string, data: array<string, mixed>}
     */
    private function decode(array $response): array
    {
        if ($response['status'] === 0) {
            $msg = 'Could not reach the WHM server.';
            if (!empty($response['error'])) {
                $msg .= ' (' . $response['error'] . ')';
            }
            return ['success' => false, 'reason' => $msg, 'data' => []];
        }

        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded)) {
            return ['success' => false, 'reason' => "Unexpected response (HTTP {$response['status']}).", 'data' => []];
        }

        if (isset($decoded['metadata'])) {
            $ok = $response['status'] === 200 && ($decoded['metadata']['result'] ?? null) === 1;

            return [
                'success' => $ok,
                'reason' => $decoded['metadata']['reason'] ?? "WHM API error (HTTP {$response['status']}).",
                'data' => is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
            ];
        }

        if (isset($decoded['cpanelresult'])) {
            $cr = $decoded['cpanelresult'];
            $innerData = is_array($cr['data'] ?? null) ? $cr['data'] : [];
            $hasError = isset($cr['error']);
            // Some legacy functions (e.g. version) carry no result flag at
            // all and just return data directly on success; others put a
            // "1"/"0" string result inside `data`.
            $resultFlag = $innerData['result'] ?? null;
            $ok = $response['status'] === 200 && !$hasError && ($resultFlag === null || (string) $resultFlag === '1');

            return [
                'success' => $ok,
                'reason' => $cr['error'] ?? ($innerData['reason'] ?? ($ok ? 'OK' : "WHM API error (HTTP {$response['status']}).")),
                'data' => $innerData,
            ];
        }

        return ['success' => false, 'reason' => "Unrecognized WHM response shape (HTTP {$response['status']}).", 'data' => []];
    }
}
