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
 * AutoSSL) can comfortably exceed 120 seconds — the bound HttpClient uses a
 * 300s timeout for this module (not a short default), and create() re-checks
 * accountsummary after a dropped connection so a slow-but-successful create
 * is reported as success rather than a stranded failure.
 *
 * NOT yet verified: the CyberPanel module (§4.4 doc note) and this
 * module's `changePassword`/`changePackage` paths specifically (the live
 * run didn't exercise those two).
 */
final class CpanelProvisioningModule implements ProvisioningModule
{
    private const DEFAULT_PORT = 2087;

    /**
     * How long to keep re-checking accountsummary after a createacct drops
     * the connection — WHM keeps building the account after the socket dies,
     * so a bounded poll distinguishes "created but slow" from "unreachable".
     */
    private const CREATE_VERIFY_ATTEMPTS = 3;
    private const CREATE_VERIFY_DELAY_SECONDS = 3;

    /**
     * @param HttpClient|null $quickHttp Short-timeout client for the read-only
     *        checks a person waits on while typing (verify_new_username). The
     *        main client keeps the long timeout createacct/modifyacct need; a
     *        hung WHM must never freeze the username modal for minutes.
     *        Optional and trailing so hand-built instances keep working.
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly ?HttpClient $quickHttp = null
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
        // WHM package resolution: an explicit per-product mapping
        // (whm_package_name) wins, then the service's own package name — the
        // exact name the client sees — and only then the server's
        // default_package config. Previously an unset whm_package_name
        // silently created the account on WHM's "default" package, giving the
        // client the wrong resources.
        $plan = (string) (
            ($params['whm_package_name'] ?? '')
            ?: ($params['product_name'] ?? '')
            ?: ($server['default_package'] ?? 'default')
        );

        $response = $this->call($server, 'createacct', [
            'username' => $username,
            'domain' => $params['domain'] ?? "{$username}.example.invalid",
            'plan' => $plan,
            'password' => $params['password'] ?? bin2hex(random_bytes(12)),
        ]);

        $decoded = $this->decode($response);

        if ($decoded['success']) {
            return $this->toResult($response);
        }

        // A createacct that drops the connection client-side (status 0 =
        // connect/timeout) can still have completed on WHM — the server keeps
        // building DNS, mail and AutoSSL after the socket dies. Confirm via
        // accountsummary before reporting failure, so a "created but slow"
        // account isn't recorded as an error and left stranded (service never
        // activated, server never assigned).
        if ($response['status'] === 0 && $this->verifyAccountCreated($server, $username)) {
            return [
                'success' => true,
                'message' => 'Account created, but the WHM response timed out — verified via accountsummary.',
            ];
        }

        return ['success' => false, 'message' => $decoded['reason']];
    }

    /**
     * Briefly polls accountsummary to confirm a createacct that dropped the
     * connection actually finished server-side. Bounded so a genuinely
     * unreachable server still fails promptly rather than hanging.
     *
     * @param array<string, mixed> $server
     */
    private function verifyAccountCreated(array $server, string $username): bool
    {
        for ($attempt = 0; $attempt < self::CREATE_VERIFY_ATTEMPTS; $attempt++) {
            $decoded = $this->decode($this->call($server, 'accountsummary', ['user' => $username]));

            if ($decoded['success'] && !empty($decoded['data']['acct'])) {
                return true;
            }

            if ($attempt + 1 < self::CREATE_VERIFY_ATTEMPTS) {
                sleep(self::CREATE_VERIFY_DELAY_SECONDS);
            }
        }

        return false;
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

    /**
     * Asks WHM whether $params['new_username'] could be used as a new account
     * name (WHM API 1 `verify_new_username`). A conflict comes back as
     * metadata.result = 0 with the reason in metadata.reason. Read-only.
     *
     * @return array{success: bool, available: bool, reachable: bool, message: string}
     */
    public function verifyNewUsername(array $params): array
    {
        $decoded = $this->decode($this->call($params['server'], 'verify_new_username', [
            'user' => (string) $params['new_username'],
        ], quick: true));

        $reachable = !str_starts_with($decoded['reason'], 'Could not reach')
            && !str_starts_with($decoded['reason'], 'Unexpected response')
            && !str_starts_with($decoded['reason'], 'Unrecognized');

        return [
            'success' => $reachable,
            'available' => $decoded['success'],
            'reachable' => $reachable,
            'message' => $decoded['success'] ? 'Available.' : $decoded['reason'],
        ];
    }

    /**
     * Renames the cPanel account (WHM `modifyacct user=<old> newuser=<new>`).
     * With rename_db=true WHM also renames the account's databases and
     * database users to the new prefix (`rename_database_objects=1`).
     *
     * A dropped connection is NOT taken as failure: WHM keeps working after
     * the socket closes, so the caller verifies with accountExists().
     *
     * @return array{success: bool, message: string, transport_error?: bool, raw?: string}
     */
    public function changeUsername(array $params): array
    {
        $query = [
            'user' => (string) $params['username'],
            'newuser' => (string) $params['new_username'],
        ];

        if (!empty($params['rename_db'])) {
            $query['rename_database_objects'] = '1';
        }

        $response = $this->call($params['server'], 'modifyacct', $query);
        $decoded = $this->decode($response);

        return [
            'success' => $decoded['success'],
            'message' => $decoded['success'] ? 'Username changed.' : $decoded['reason'],
            'transport_error' => (int) ($response['status'] ?? 0) === 0,
            'raw' => substr((string) ($response['body'] ?? ''), 0, 2000),
        ];
    }

    /**
     * Whether an account named $params['username'] exists on the server
     * (`accountsummary`). `known` is false when the server could not be
     * asked — never read an unreachable server as "account missing".
     *
     * @return array{known: bool, exists: bool, domain: ?string, message: string}
     */
    public function accountExists(array $params): array
    {
        $response = $this->call($params['server'], 'accountsummary', ['user' => (string) $params['username']]);

        if ((int) ($response['status'] ?? 0) === 0) {
            return ['known' => false, 'exists' => false, 'domain' => null, 'message' => 'Could not reach the WHM server.'];
        }

        $decoded = $this->decode($response);
        $acct = $decoded['data']['acct'][0] ?? null;

        if ($decoded['success'] && is_array($acct)) {
            return ['known' => true, 'exists' => true, 'domain' => isset($acct['domain']) ? (string) $acct['domain'] : null, 'message' => 'Account exists.'];
        }

        $json = json_decode((string) ($response['body'] ?? ''), true);
        $known = is_array($json);

        return ['known' => $known, 'exists' => false, 'domain' => null, 'message' => $decoded['reason']];
    }

    /**
     * Every account on the server, as [username => domain] (`listaccts
     * want=user,domain`). Used to keep the local availability cache fresh so
     * the client-side precheck never has to wait on WHM.
     *
     * @return array{success: bool, accounts: array<string, string>, message: string}
     */
    public function listAccounts(array $params): array
    {
        $decoded = $this->decode($this->call($params['server'], 'listaccts', ['want' => 'user,domain']));

        if (!$decoded['success']) {
            return ['success' => false, 'accounts' => [], 'message' => $decoded['reason']];
        }

        $accounts = [];

        foreach ((array) ($decoded['data']['acct'] ?? []) as $acct) {
            if (is_array($acct) && isset($acct['user'])) {
                $accounts[strtolower((string) $acct['user'])] = strtolower((string) ($acct['domain'] ?? ''));
            }
        }

        return ['success' => true, 'accounts' => $accounts, 'message' => count($accounts) . ' accounts.'];
    }

    /**
     * 'mysql' | 'mariadb' | null (unknown) — decides whether the "first 8
     * characters must be unique" rule applies on this server.
     */
    public function databaseEngine(array $params): ?string
    {
        $decoded = $this->decode($this->call($params['server'], 'current_mysql_version', []));

        if (!$decoded['success']) {
            return null;
        }

        $server = strtolower((string) ($decoded['data']['server'] ?? ''));
        $version = strtolower((string) ($decoded['data']['version'] ?? ''));

        if ($server === 'mariadb' || str_contains($version, 'mariadb')) {
            return 'mariadb';
        }

        return $server === 'mysql' || $version !== '' ? 'mysql' : null;
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
    private function call(array $server, string $function, array $query, bool $quick = false): array
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

        return ($quick && $this->quickHttp !== null ? $this->quickHttp : $this->http)->request('GET', $url, [
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
