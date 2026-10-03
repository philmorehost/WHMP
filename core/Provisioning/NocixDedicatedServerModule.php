<?php

declare(strict_types=1);

namespace CodeVault\Provisioning;

use CodeVault\Modules\ProvisioningModule;

/**
 * Dedicated-server control through Nocix's client API (BETA,
 * https://my.nocix.net/apidoc/): `https://my.nocix.net/api/{call}/` with HTTP
 * Basic auth `username:token`. Every result is JSON; a failure is
 * `{"error": "..."}`. Wholesale accounts use my.wholesaleinternet.net instead,
 * picked when the server record's hostname names it.
 *
 * This used to call https://manage.nocix.net/api, a host that does not exist
 * (NXDOMAIN), so every call failed with "Could not reach the Nocix API". It also
 * said reboot and OS reload were not in the API. They are, and they are what this
 * module is for:
 *
 *   reboot-server?service_id=        restart (the only power action Nocix has)
 *   os-list?service_id=              the OS names this server can be reloaded with
 *   os-reload?service_id=&os=        reload with one of those names
 *   reloadstatus?service=            "Pending" or "Completed" for the latest reload
 *   get-server-credentials?service_id=  the login stored in the portal (Nocix sets
 *                                    it on reload; the client needs it afterwards)
 *
 * Which Nocix service a WHMP service is: `remote_id` when an admin linked it, else
 * a numeric `username` (the older convention), else the service whose IP block
 * contains the service's dedicated or assigned IP (list-services-details).
 *
 * Still not in Nocix's API, so still refused plainly: ordering, cancellation,
 * plan changes, password changes and a remote console.
 */
final class NocixDedicatedServerModule implements ProvisioningModule, LinksRemoteServices
{
    private const DEFAULT_HOST = 'my.nocix.net';

    private const WHOLESALE_HOST = 'my.wholesaleinternet.net';

    public const NOT_LINKED = 'This server is not linked to a Nocix service yet. An admin can link it on the service page.';

    /** @var array<string, array{ref: ?string, via: string}> per-request lookups */
    private array $resolved = [];

    public function __construct(
        private readonly HttpClient $http
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'Nocix Dedicated Server',
            'description' => 'Restarts and reloads the OS of Nocix dedicated servers, and disconnects/reconnects them on suspend, via the Nocix client API.',
            'version' => '2.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [];
    }

    public function create(array $params): array
    {
        return [
            'success' => false,
            'message' => 'Nocix does not support ordering dedicated servers via API. Buy the server in the Nocix portal, then link this service to it on the admin service page.',
        ];
    }

    public function suspend(array $params): array
    {
        return $this->serviceCall($params, 'disconnect-server', 'Server disconnected from the network.');
    }

    public function unsuspend(array $params): array
    {
        return $this->serviceCall($params, 'reconnect-server', 'Server reconnected to the network.');
    }

    public function terminate(array $params): array
    {
        return [
            'success' => false,
            'message' => 'Nocix does not expose a server cancellation endpoint. Cancel the service through the Nocix client portal or support.',
        ];
    }

    public function changePassword(array $params): array
    {
        return [
            'success' => false,
            'message' => 'Nocix does not expose a root password change endpoint for dedicated servers.',
        ];
    }

    public function changePackage(array $params): array
    {
        return [
            'success' => false,
            'message' => 'Nocix does not support changing a dedicated server\'s plan via API. Hardware specification changes require a new server.',
        ];
    }

    public function singleSignOn(array $params): array
    {
        return [
            'success' => false,
            'message' => 'Nocix does not expose a remote console endpoint via API.',
        ];
    }

    /**
     * Restart is the only power action Nocix publishes (reboot-server, for servers
     * "that have reboot capabilities"). There is no start or stop.
     *
     * @param array<string, mixed> $params
     */
    public function power(array $params, string $action): array
    {
        if ($action !== 'restart') {
            return ['success' => false, 'message' => 'Nocix dedicated servers can only be restarted through the API, not started or stopped.'];
        }

        return $this->serviceCall($params, 'reboot-server', 'Restart sent. The server is rebooting and will be back in a few minutes.');
    }

    /**
     * os-list. Nocix does not document the result's shape, so this accepts a list of
     * names, a map of id => name, or a list of objects carrying a name. The NAME is
     * what os-reload takes back ("encoded url of OS name ... obtained in os_list"),
     * so it is returned as `file`, the value the reload form posts.
     *
     * @param array<string, mixed> $params
     * @return array{success: bool, message: string, templates?: array<int, array{file: string, name: string}>}
     */
    public function osTemplates(array $params): array
    {
        $id = $this->resolveId($params);

        if ($id === null) {
            return ['success' => false, 'message' => self::NOT_LINKED];
        }

        $decoded = $this->decode($this->call($params['server'], 'os-list', ['service_id' => $id]));

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message']];
        }

        $templates = self::parseOsList($decoded['data']);

        if ($templates === []) {
            return ['success' => false, 'message' => 'Nocix listed no operating systems for this server.'];
        }

        return ['success' => true, 'message' => '', 'templates' => $templates];
    }

    /**
     * @param mixed $data
     * @return array<int, array{file: string, name: string}>
     */
    public static function parseOsList(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        // A wrapper such as {"os": [...]} or {"oslist": [...]}.
        foreach (['os', 'oslist', 'os_list', 'list', 'data', 'operating_systems'] as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && count($data) === 1) {
                $data = $data[$key];
                break;
            }
        }

        $out = [];

        foreach ($data as $entry) {
            $name = null;

            if (is_string($entry)) {
                $name = $entry;
            } elseif (is_array($entry)) {
                foreach (['os', 'name', 'os_name', 'title', 'label', 'value'] as $key) {
                    if (isset($entry[$key]) && is_scalar($entry[$key]) && trim((string) $entry[$key]) !== '') {
                        $name = (string) $entry[$key];
                        break;
                    }
                }
            }

            $name = $name === null ? '' : trim($name);

            if ($name !== '' && !isset($out[$name])) {
                $out[$name] = ['file' => $name, 'name' => $name];
            }
        }

        return array_values($out);
    }

    /**
     * os-reload. Wipes the server and installs `template` (a name from os-list).
     * Nocix takes no root password here; it sets the login itself and keeps it in
     * the portal, where get-server-credentials reads it once the reload is done.
     *
     * @param array<string, mixed> $params
     */
    public function reinstall(array $params): array
    {
        $template = trim((string) ($params['template'] ?? ''));

        if ($template === '') {
            return ['success' => false, 'message' => 'Choose an operating system from the list this server supports.'];
        }

        $id = $this->resolveId($params);

        if ($id === null) {
            return ['success' => false, 'message' => self::NOT_LINKED];
        }

        $decoded = $this->decode($this->call($params['server'], 'os-reload', ['service_id' => $id, 'os' => $template]));

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message']];
        }

        return ['success' => true, 'message' => self::messageFrom($decoded['data']) ?? 'OS reload has been queued.'];
    }

    /**
     * reloadstatus: the state of the most recent reload. Nocix answers with an error
     * when there has never been one, which is reported as status null, not failure.
     *
     * @param array<string, mixed> $params
     * @return array{success: bool, message: string, status?: ?string}
     */
    public function reloadStatus(array $params): array
    {
        $id = $this->resolveId($params);

        if ($id === null) {
            return ['success' => false, 'message' => self::NOT_LINKED];
        }

        $response = $this->call($params['server'], 'reloadstatus', ['service' => $id]);
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            if ($response['status'] >= 200 && $response['status'] < 500 && stripos($decoded['message'], 'reload') !== false) {
                return ['success' => true, 'message' => $decoded['message'], 'status' => null];
            }

            return ['success' => false, 'message' => $decoded['message']];
        }

        $status = is_array($decoded['data']) && isset($decoded['data']['status']) ? trim((string) $decoded['data']['status']) : '';

        return ['success' => true, 'message' => '', 'status' => $status !== '' ? $status : null];
    }

    /**
     * get-server-credentials: the server login Nocix keeps in its portal. Sensitive.
     * The caller must show it, not log or store it.
     *
     * @param array<string, mixed> $params
     * @return array{success: bool, message: string, username?: string, password?: string}
     */
    public function serverCredentials(array $params): array
    {
        $id = $this->resolveId($params);

        if ($id === null) {
            return ['success' => false, 'message' => self::NOT_LINKED];
        }

        $decoded = $this->decode($this->call($params['server'], 'get-server-credentials', ['service_id' => $id]));

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message']];
        }

        $data = is_array($decoded['data']) ? $decoded['data'] : [];
        $username = (string) ($data['username'] ?? '');
        $password = (string) ($data['password'] ?? '');

        if ($password === '') {
            return ['success' => false, 'message' => 'Nocix has no stored login for this server.'];
        }

        return ['success' => true, 'message' => '', 'username' => $username, 'password' => $password];
    }

    public function usage(array $params): array
    {
        $id = $this->resolveId($params);

        if ($id === null) {
            return ['success' => false, 'message' => self::NOT_LINKED];
        }

        $decoded = $this->decode($this->call($params['server'], 'bandwidth-graphing', ['service_id' => $id]));

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
        $decoded = $this->decode($this->call($params['server'], 'list-services-details', []));

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message']];
        }

        return ['success' => true, 'message' => 'Connected. API credentials are valid.'];
    }

    /**
     * The account's services (list-services-details, which leaves out cancelled
     * ones) for the admin's "link this service" picker.
     *
     * @param array<string, mixed> $server
     */
    public function remoteServices(array $server): array
    {
        $decoded = $this->decode($this->call($server, 'list-services-details', []));

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message'], 'services' => []];
        }

        $services = [];

        foreach (self::serviceRows($decoded['data']) as $id => $row) {
            $ips = $row['ips'];
            $label = '#' . $id
                . ($ips !== [] ? ' · ' . implode(', ', array_slice($ips, 0, 2)) : '')
                . ($row['name'] !== '' ? ' · ' . $row['name'] : '')
                . ($row['type'] !== '' ? ' · ' . $row['type'] : '');

            $services[] = [
                'ref' => (string) $id,
                'label' => $label,
                'hostname' => '',
                // The first address of the first block: a usable primary IP to fill in.
                'ip' => $ips !== [] ? (string) strtok($ips[0], '/') : '',
                'status' => '',
            ];
        }

        return ['success' => true, 'message' => '', 'services' => $services];
    }

    /** @param array<string, mixed> $params */
    public function resolveRemote(array $params): array
    {
        $linked = trim((string) ($params['remote_id'] ?? ''));

        if ($linked !== '' && self::isServiceId($linked)) {
            return ['ref' => $linked, 'via' => 'linked'];
        }

        $username = trim((string) ($params['username'] ?? ''));

        if ($username !== '' && self::isServiceId($username)) {
            return ['ref' => $username, 'via' => 'username'];
        }

        $ips = self::serviceIps($params);

        if ($ips === []) {
            return ['ref' => null, 'via' => 'none'];
        }

        $cacheKey = md5(json_encode([$params['server']['api_username'] ?? '', $params['server']['hostname'] ?? '', $ips]));

        if (isset($this->resolved[$cacheKey])) {
            return $this->resolved[$cacheKey];
        }

        $decoded = $this->decode($this->call($params['server'], 'list-services-details', []));
        $result = ['ref' => null, 'via' => 'none'];

        if ($decoded['success']) {
            foreach (self::serviceRows($decoded['data']) as $id => $row) {
                foreach ($row['ips'] as $block) {
                    foreach ($ips as $ip) {
                        if (self::ipInBlock($ip, $block)) {
                            $result = ['ref' => (string) $id, 'via' => 'ip'];
                            break 3;
                        }
                    }
                }
            }
        }

        return $this->resolved[$cacheKey] = $result;
    }

    /** Whether $ip falls inside $block ("a.b.c.d/nn", IPv6 CIDR, or a bare address). */
    public static function ipInBlock(string $ip, string $block): bool
    {
        [$network, $bits] = array_pad(explode('/', trim($block), 2), 2, null);
        $ipBin = @inet_pton(trim($ip));
        $netBin = @inet_pton((string) $network);

        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }

        $max = strlen($ipBin) * 8;
        $bits = $bits === null || $bits === '' ? $max : (int) $bits;

        if ($bits < 0 || $bits > $max) {
            return false;
        }

        $full = intdiv($bits, 8);

        if (substr($ipBin, 0, $full) !== substr($netBin, 0, $full)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ipBin[$full]) & $mask) === (ord($netBin[$full]) & $mask);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function serviceCall(array $params, string $endpoint, string $successMessage): array
    {
        $id = $this->resolveId($params);

        if ($id === null) {
            return ['success' => false, 'message' => self::NOT_LINKED];
        }

        $decoded = $this->decode($this->call($params['server'], $endpoint, ['service_id' => $id]));

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message']];
        }

        return ['success' => true, 'message' => $successMessage];
    }

    /** @param array<string, mixed> $params */
    private function resolveId(array $params): ?string
    {
        return $this->resolveRemote($params)['ref'];
    }

    private static function isServiceId(string $value): bool
    {
        // "A positive integer service ID, without leading zeros."
        return preg_match('/^[1-9]\d{0,11}$/', $value) === 1;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, string>
     */
    private static function serviceIps(array $params): array
    {
        $ips = [];

        foreach (array_merge([(string) ($params['dedicated_ip'] ?? '')], preg_split('/[\s,]+/', (string) ($params['assigned_ips'] ?? '')) ?: []) as $ip) {
            $ip = trim((string) strtok(trim($ip), '/'));

            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $ips[$ip] = $ip;
            }
        }

        return array_values($ips);
    }

    /**
     * list-services-details: an object keyed by service id, each with name, type and
     * ipaddress (a list of CIDR blocks). list-services' comma-separated `ipaddress`
     * string is accepted too.
     *
     * @return array<string, array{name: string, type: string, ips: array<int, string>}>
     */
    private static function serviceRows(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $rows = [];

        foreach ($data as $key => $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = isset($row['id']) && self::isServiceId((string) $row['id']) ? (string) $row['id'] : (string) $key;

            if (!self::isServiceId($id)) {
                continue;
            }

            $rawIps = $row['ipaddress'] ?? ($row['ip'] ?? []);
            $ips = is_array($rawIps) ? $rawIps : preg_split('/\s*,\s*/', (string) $rawIps);
            $ips = array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), (array) $ips), static fn (string $v): bool => $v !== ''));

            $rows[$id] = [
                'name' => trim((string) ($row['name'] ?? '')),
                'type' => trim((string) ($row['type'] ?? '')),
                'ips' => $ips,
            ];
        }

        return $rows;
    }

    private static function messageFrom(mixed $data): ?string
    {
        if (!is_array($data)) {
            return is_string($data) && trim($data) !== '' ? trim($data) : null;
        }

        foreach (['success', 'message', 'status', 'text'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') {
                return trim($data[$key]);
            }
        }

        return null;
    }

    /** my.nocix.net, or my.wholesaleinternet.net when the server record names it. */
    private static function baseUrl(array $server): string
    {
        $hostname = strtolower((string) ($server['hostname'] ?? ''));

        return 'https://' . (str_contains($hostname, 'wholesaleinternet') ? self::WHOLESALE_HOST : self::DEFAULT_HOST) . '/api';
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $query
     * @return array{status: int, body: string}
     */
    private function call(array $server, string $endpoint, array $query): array
    {
        $url = self::baseUrl($server) . '/' . $endpoint . '/';

        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $credentials = base64_encode(($server['api_username'] ?? '') . ':' . ($server['api_token'] ?? ''));

        return $this->http->request('GET', $url, ['Authorization' => "Basic {$credentials}", 'Accept' => 'application/json']);
    }

    /**
     * "A successful call will return with either a result set, or an error message
     * contained within a JSON array (error=>message)." All results are JSON, so a
     * body that is not (a login page, a proxy error) is a failure, never a success.
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

        if (is_array($decoded) && isset($decoded['error'])) {
            $error = is_scalar($decoded['error']) ? trim((string) $decoded['error']) : '';

            return ['success' => false, 'message' => $error !== '' ? $error : "Nocix API error (HTTP {$response['status']}).", 'data' => null];
        }

        if ($response['status'] === 401 || $response['status'] === 403) {
            return ['success' => false, 'message' => 'Nocix rejected the API username or token.', 'data' => null];
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return ['success' => false, 'message' => "Nocix API error (HTTP {$response['status']}).", 'data' => null];
        }

        if ($decoded === null && strtolower(trim($response['body'])) !== 'null') {
            return ['success' => false, 'message' => 'Nocix returned an unexpected (non-JSON) response.', 'data' => null];
        }

        return ['success' => true, 'message' => '', 'data' => $decoded];
    }
}
