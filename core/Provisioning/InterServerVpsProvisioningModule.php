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
 * WHICH VPS IS THIS SERVICE?
 *
 * InterServer addresses a VPS by its `vps_uuid` in every `/vps/{id}/*` path. The
 * legacy integer `vps_id` is still accepted, but is deprecated. WHMP finds it in this
 * order (see resolveRemote()):
 *
 *   1. `services.remote_id`: the admin linked the service to a VPS explicitly from
 *      the service page. This is the reliable way for VPSes bought by hand on
 *      InterServer and then entered into WHMP, whose hostname rarely matches.
 *   2. Otherwise GET /vps (the account's VPS list), matched on the service's hostname,
 *      then its primary or assigned IPs, then its username. API-ordered services
 *      match by hostname, because create() orders the VPS with the WHMP username as
 *      its hostname.
 *
 * The UUID is used whenever the list provides one; the integer id is only a fallback.
 *
 * DESTRUCTIVE CALLS
 *
 * OS reinstall and backup restore re-check the InterServer ACCOUNT password on top of
 * the API key. The password is stored encrypted on the server record
 * (servers.account_secret). ProvisioningService decrypts it into
 * `server.account_password` for these two calls only.
 */
final class InterServerVpsProvisioningModule implements ProvisioningModule, LinksRemoteServices
{
    private const BASE_URL = 'https://my.interserver.net/apiv2';

    private const NOT_LINKED = 'This VPS is not linked to a VPS on the InterServer account yet: no VPS there matches its hostname or IP. An admin can link it from the service page.';

    /** @var array<string, string> match key => InterServer VPS ref, per request */
    private array $vpsIdCache = [];

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
                ? "VPS order placed (service #{$serviceId})."
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

    /**
     * Power state. All three are side-effecting GETs (InterServer's own
     * design, not a mistake here) returning `{text, queueId}` — the action
     * is queued on the hypervisor, so a 200 means "accepted", not "done".
     * `restart` is a real endpoint and is what the docs recommend over
     * stop-then-start, since it preserves boot context and lets the
     * hypervisor sequence it atomically.
     *
     * A 409 "VPS is not active" comes back for cancelled/suspended
     * services; that message is surfaced verbatim rather than flattened
     * into a generic failure.
     */
    public function power(array $params, string $action): array
    {
        $path = match ($action) {
            'start' => '/vps/{id}/start',
            'stop' => '/vps/{id}/stop',
            'restart' => '/vps/{id}/restart',
            default => null,
        };

        if ($path === null) {
            return ['success' => false, 'message' => "Unsupported power action \"{$action}\"."];
        }

        $message = match ($action) {
            'start' => 'Power on has been queued — allow up to 30 seconds.',
            'stop' => 'Power off has been queued — allow up to 30 seconds.',
            default => 'Reboot has been queued — allow up to 2 minutes.',
        };

        return $this->lifecycleAction($params, 'GET', $path, $message);
    }

    /**
     * getVpsBackup — queues an on-demand snapshot. Backups are disabled
     * server-side on HyperV/OpenVZ/Virtuozzo (400 "Backups are disabled for
     * this type") and capped at 4 per VPS, both of which come back as real
     * API errors this surfaces rather than pre-guessing.
     */
    public function createBackup(array $params): array
    {
        return $this->lifecycleAction($params, 'GET', '/vps/{id}/backup', 'Snapshot queued — allow a few minutes for it to complete.');
    }

    /**
     * getVpsBackups. Each row's `name` is the canonical identifier (there is
     * no integer id); a restore is keyed by the composite
     * `<type>:<service>:<name>`, which is precomputed here as `ref` so
     * callers never have to assemble it themselves.
     *
     * @return array{success: bool, message: string, backups: array<int, array<string, mixed>>}
     */
    public function listBackups(array $params): array
    {
        $vpsId = $this->resolveVpsId($params);

        if ($vpsId === null) {
            return ['success' => false, 'message' => self::NOT_LINKED, 'backups' => []];
        }

        $decoded = $this->decode($this->call($params['server'], 'GET', "/vps/{$vpsId}/backups", null));

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message'], 'backups' => []];
        }

        $rows = $decoded['data'];
        $backups = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || ($row['name'] ?? '') === '') {
                continue;
            }

            $type = (string) ($row['type'] ?? '');
            $service = (string) ($row['service'] ?? $vpsId);
            $name = (string) $row['name'];

            $backups[] = [
                'name' => $name,
                'type' => $type,
                'sizeBytes' => isset($row['size']) ? (int) $row['size'] : null,
                'createdAt' => isset($row['date']) && (int) $row['date'] > 0 ? (int) $row['date'] : null,
                'ref' => "{$type}:{$service}:{$name}",
            ];
        }

        return ['success' => true, 'message' => '', 'backups' => $backups];
    }

    /**
     * getVpsSlices — current allocation plus the range and prorated cost of
     * changing it. A "slice" bundles RAM/disk/CPU and is InterServer's unit
     * of vertical scaling; `min_slices` is the current count (downgrades go
     * below it, upgrades above) and `max_slices` is capped by host capacity.
     *
     * @return array{success: bool, message: string, slices: array<string, mixed>}
     */
    public function sliceOptions(array $params): array
    {
        $vpsId = $this->resolveVpsId($params);

        if ($vpsId === null) {
            return ['success' => false, 'message' => self::NOT_LINKED, 'slices' => []];
        }

        $decoded = $this->decode($this->call($params['server'], 'GET', "/vps/{$vpsId}/slices", null));

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message'], 'slices' => []];
        }

        $data = is_array($decoded['data']) ? $decoded['data'] : [];

        return [
            'success' => true,
            'message' => '',
            'slices' => [
                'current' => isset($data['vps_slices']) ? (int) $data['vps_slices'] : null,
                'min' => isset($data['min_slices']) ? (int) $data['min_slices'] : null,
                'max' => isset($data['max_slices']) ? (int) $data['max_slices'] : null,
                'sliceCost' => isset($data['slice_cost']) ? (float) $data['slice_cost'] : null,
                'proratedSliceCost' => isset($data['prorated_slice_cost']) ? (float) $data['prorated_slice_cost'] : null,
                'sliceRamGb' => isset($data['slice_ram']) ? (int) $data['slice_ram'] : null,
                'sliceHdGb' => isset($data['slice_hd']) ? (int) $data['slice_hd'] : null,
            ],
        ];
    }

    /**
     * getVpsInfo — the real service record, used so the client page can show
     * this VPS's actual `vps_status` instead of a static "Running" badge.
     *
     * @return array{success: bool, message: string, info: array<string, mixed>}
     */
    public function info(array $params): array
    {
        $vpsId = $this->resolveVpsId($params);

        if ($vpsId === null) {
            return ['success' => false, 'message' => self::NOT_LINKED, 'info' => []];
        }

        $decoded = $this->decode($this->call($params['server'], 'GET', "/vps/{$vpsId}", null));

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message'], 'info' => []];
        }

        $data = is_array($decoded['data']) ? $decoded['data'] : [];
        // getVpsInfo nests the VPS row under `serviceInfo`, next to `serviceMaster`
        // (the host node) and `billingDetails`. Reading the top level, as this used to,
        // found nothing, so the status badge never showed. Older flat shapes still work.
        $row = is_array($data['serviceInfo'] ?? null) ? $data['serviceInfo'] : $data;
        $power = (string) ($row['vps_server_status'] ?? '');

        return [
            'success' => true,
            'message' => '',
            'info' => [
                // The machine's power state when reported (running/stopped), else the
                // service state (active/suspended).
                'status' => $power !== '' ? $power : (string) ($row['vps_status'] ?? ''),
                'serviceStatus' => (string) ($row['vps_status'] ?? ''),
                'hostname' => (string) ($row['vps_hostname'] ?? ''),
                'ip' => (string) ($row['vps_ip'] ?? ''),
                'ipv6' => (string) ($row['vps_ipv6'] ?? ''),
                'os' => (string) ($row['vps_os'] ?? ''),
                'slices' => isset($row['vps_slices']) ? (int) $row['vps_slices'] : null,
                'plan' => (string) ($data['services_name'] ?? $row['services_name'] ?? ''),
            ],
        ];
    }

    public function changePassword(array $params): array
    {
        $vpsId = $this->resolveVpsId($params);

        if ($vpsId === null) {
            return ['success' => false, 'message' => self::NOT_LINKED];
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
            return ['success' => false, 'message' => self::NOT_LINKED];
        }

        $slices = (int) ($params['package'] ?? $params['slices'] ?? 0);

        if ($slices <= 0) {
            return ['success' => false, 'message' => 'A target slice count is required to change the VPS package.'];
        }

        $response = $this->call($params['server'], 'POST', "/vps/{$vpsId}/slices", ['slices' => $slices]);

        return $this->toResult($response);
    }

    /**
     * The out-of-band VNC console.
     *
     * InterServer only accepts console connections from one allowed IPv4 address.
     * postVpsSetupVnc sets that address (`vnc`) and re-provisions the listener, which
     * takes about two minutes. So this first allows the CLIENT's own IP (the browser
     * that pressed the button), then reads where to connect. The VNC listener runs on
     * the host node (`serviceMaster.vps_ip`) at the VPS's `vps_vnc_port`, both from
     * getVpsInfo. getVpsSetupVnc has no fixed schema, so it is only a fallback.
     *
     * A private or IPv6 client address cannot be allowed (the API validates IPv4), so
     * in that case only the existing details are read.
     *
     * @param array<string, mixed> $params
     * @return array{success: bool, message: string, url?: ?string, host?: ?string, port?: ?int, allowedIp?: ?string}
     */
    public function console(array $params, string $clientIp = ''): array
    {
        $ref = $this->resolveVpsId($params);

        if ($ref === null) {
            return ['success' => false, 'message' => self::NOT_LINKED];
        }

        $allowed = null;

        if (filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            $setup = $this->decode($this->call($params['server'], 'POST', "/vps/{$ref}/setup_vnc", ['vnc' => $clientIp]));

            if (!$setup['success']) {
                return ['success' => false, 'message' => $setup['message'] !== '' ? $setup['message'] : 'The provider would not open the console.'];
            }

            $allowed = $clientIp;
        }

        [$host, $port] = $this->consoleEndpoint($params['server'], $ref);

        if ($host === null) {
            if ($allowed !== null) {
                return [
                    'success' => true,
                    'url' => null,
                    'host' => null,
                    'port' => $port,
                    'allowedIp' => $allowed,
                    'message' => "Console access is being opened for your IP {$allowed}. Allow about 2 minutes, then press Get Console Details again to see the address to connect to.",
                ];
            }

            return ['success' => false, 'message' => 'No VNC console details are available for this VPS yet.'];
        }

        $address = $port !== null ? "{$host}:{$port}" : $host;

        return [
            'success' => true,
            'url' => "vnc://{$address}",
            'host' => $host,
            'port' => $port,
            'allowedIp' => $allowed,
            'message' => "VNC console: connect a VNC client to {$address}"
                . ($allowed !== null ? " from your IP {$allowed}. Access was just opened for that IP; allow up to 2 minutes." : '.'),
        ];
    }

    /** Kept for the ProvisioningModule contract: the console, without allowing an IP. */
    public function singleSignOn(array $params): array
    {
        return $this->console($params, (string) ($params['client_ip'] ?? ''));
    }

    /**
     * Where the VNC listener for this VPS is: [host, port].
     *
     * @param array<string, mixed> $server
     * @return array{0: ?string, 1: ?int}
     */
    private function consoleEndpoint(array $server, string $ref): array
    {
        $host = null;
        $port = null;
        $info = $this->decode($this->call($server, 'GET', "/vps/{$ref}", null));

        if ($info['success'] && is_array($info['data'])) {
            $row = is_array($info['data']['serviceInfo'] ?? null) ? $info['data']['serviceInfo'] : $info['data'];
            $master = is_array($info['data']['serviceMaster'] ?? null) ? $info['data']['serviceMaster'] : [];
            $port = self::portOf($row['vps_vnc_port'] ?? null);
            $host = self::ipOf($master['vps_ip'] ?? null);
        }

        if ($host === null || $port === null) {
            $vnc = $this->decode($this->call($server, 'GET', "/vps/{$ref}/setup_vnc", null));
            $data = $vnc['success'] && is_array($vnc['data']) ? $vnc['data'] : [];
            $host ??= self::ipOf($data['host'] ?? $data['ip'] ?? $data['vnc_host'] ?? $data['vnc_ip'] ?? null);
            $port ??= self::portOf($data['port'] ?? $data['vnc_port'] ?? null);
        }

        return [$host, $port];
    }

    private static function ipOf(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' && filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }

    private static function portOf(mixed $value): ?int
    {
        $port = (int) $value;

        return $port > 0 && $port < 65536 ? $port : null;
    }

    public function usage(array $params): array
    {
        $vpsId = $this->resolveVpsId($params);

        if ($vpsId === null) {
            return ['success' => false, 'message' => self::NOT_LINKED];
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

    /**
     * getVpsReinstallOs — step 1 of the reinstall flow. Returns only the
     * templates this VPS's backing hypervisor can actually run, filtered
     * server-side by platform and by `template_available=1` for non-admin
     * callers. The `template_file` of a chosen row (e.g.
     * `centos-7-x86_64.qcow2`) is the canonical id `reinstall()` accepts —
     * there is no fixed list of OS slugs to hardcode against, which is why
     * the client-facing picker must be populated from here.
     *
     * @return array{success: bool, message: string, templates: array<int, array<string, mixed>>}
     */
    public function osTemplates(array $params): array
    {
        $vpsId = $this->resolveVpsId($params);

        if ($vpsId === null) {
            return ['success' => false, 'message' => self::NOT_LINKED, 'templates' => []];
        }

        $decoded = $this->decode($this->call($params['server'], 'GET', "/vps/{$vpsId}/reinstall_os", null));

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message'], 'templates' => []];
        }

        $templates = [];

        foreach ((array) ($decoded['data']['templates'] ?? []) as $row) {
            if (!is_array($row) || ($row['template_file'] ?? '') === '') {
                continue;
            }

            $templates[] = [
                'file' => (string) $row['template_file'],
                'name' => trim((string) ($row['template_name'] ?? '') . ' ' . (string) ($row['template_version'] ?? '')),
            ];
        }

        return ['success' => true, 'message' => '', 'templates' => $templates];
    }

    /**
     * postVpsReinstallOs. Two corrections against what this method sent
     * before: the path is `/vps/{id}/reinstall_os` (plain `/reinstall` is
     * not a route — it 404'd), and the body is `{template, localPassword}`
     * where `template` is a `template_file` from `osTemplates()`, not a bare
     * `osVersion` slug like "ubuntu24".
     *
     * `localPassword` is the InterServer *account* password, re-checked on
     * every call because this wipes the disk with no rollback. It comes from
     * the server record (`server.account_password`, decrypted by
     * ProvisioningService) unless the caller passes one. When neither exists,
     * this says so rather than firing a destructive call that would be
     * rejected, and the client's request becomes a support ticket instead.
     */
    public function reinstall(array $params): array
    {
        $vpsId = $this->resolveVpsId($params);

        if ($vpsId === null) {
            return ['success' => false, 'message' => self::NOT_LINKED];
        }

        $template = trim((string) ($params['template'] ?? ''));
        $localPassword = (string) ($params['localPassword'] ?? '');

        if ($localPassword === '') {
            $localPassword = (string) ($params['server']['account_password'] ?? '');
        }

        if ($template === '') {
            return ['success' => false, 'message' => 'An OS template is required — choose one from the templates this VPS supports.'];
        }

        if ($localPassword === '') {
            return [
                'success' => false,
                'message' => 'The hosting provider requires the InterServer account password to reinstall a VPS, and none is saved on this server record (Admin → Servers → edit → Account password).',
            ];
        }

        $body = ['template' => $template, 'localPassword' => $localPassword];

        if (($params['password'] ?? '') !== '') {
            $body['password'] = (string) $params['password'];
        }

        $response = $this->call($params['server'], 'POST', "/vps/{$vpsId}/reinstall_os", $body);

        return $this->toResult($response, 'VPS OS reinstallation has been queued.');
    }

    /**
     * postVpsRestore: overwrite the disk from one of the VPS's own backups.
     *
     * `backup` is the composite `<type>:<service>:<name>` that listBackups() returns
     * as `ref`. InterServer re-checks the account password (`password`), as for a
     * reinstall, and rejects a backup that is not in this VPS's own list.
     *
     * @param array<string, mixed> $params
     */
    public function restore(array $params): array
    {
        $ref = $this->resolveVpsId($params);

        if ($ref === null) {
            return ['success' => false, 'message' => self::NOT_LINKED];
        }

        $backup = trim((string) ($params['backup'] ?? ''));

        if ($backup === '') {
            return ['success' => false, 'message' => 'Choose which backup to restore from.'];
        }

        $password = (string) ($params['localPassword'] ?? '');

        if ($password === '') {
            $password = (string) ($params['server']['account_password'] ?? '');
        }

        if ($password === '') {
            return [
                'success' => false,
                'message' => 'The hosting provider requires the InterServer account password to restore a backup, and none is saved on this server record (Admin → Servers → edit → Account password).',
            ];
        }

        $response = $this->call($params['server'], 'POST', "/vps/{$ref}/restore", ['backup' => $backup, 'password' => $password]);

        return $this->toResult($response, 'Restore has been queued. Allow up to 10 minutes for it to complete.');
    }

    /** {@inheritDoc} */
    public function remoteServices(array $server): array
    {
        $rows = $this->vpsRows($server);

        if (!$rows['success']) {
            return ['success' => false, 'message' => $rows['message'], 'services' => []];
        }

        $services = [];

        foreach ($rows['rows'] as $row) {
            $ref = self::refOf($row);

            if ($ref === '') {
                continue;
            }

            $hostname = (string) ($row['vps_hostname'] ?? '');
            $ip = (string) ($row['vps_ip'] ?? '');
            $status = (string) ($row['vps_status'] ?? '');
            $name = trim((string) ($row['vps_name'] ?? ''));

            $services[] = [
                'ref' => $ref,
                'id' => (string) ($row['vps_id'] ?? ''),
                'hostname' => $hostname,
                'ip' => $ip,
                'status' => $status,
                'label' => trim(($hostname !== '' ? $hostname : 'VPS ' . ($row['vps_id'] ?? '')) . ($ip !== '' ? " ({$ip})" : '')
                    . ($name !== '' && $name !== $hostname ? " · {$name}" : '')
                    . ($status !== '' && $status !== 'active' ? " · {$status}" : '')),
            ];
        }

        return ['success' => true, 'message' => '', 'services' => $services];
    }

    /** {@inheritDoc} */
    public function resolveRemote(array $params): array
    {
        $linked = trim((string) ($params['remote_id'] ?? ''));

        // Only something shaped like an InterServer id (a UUID or an integer) goes into
        // a URL path. Anything else is treated as "not linked".
        if ($linked !== '' && preg_match('/^[A-Za-z0-9-]{1,64}$/', $linked) === 1) {
            return ['ref' => $linked, 'via' => 'linked'];
        }

        $hostname = strtolower(trim((string) ($params['hostname'] ?? '')));
        $username = strtolower(trim((string) ($params['username'] ?? '')));
        $ips = self::serviceIps($params);

        if ($hostname === '' && $username === '' && $ips === []) {
            return ['ref' => null, 'via' => 'none'];
        }

        $cacheKey = $hostname . '|' . implode(',', $ips) . '|' . $username;

        // Memoized for the life of the request: rendering a service page reads
        // status, reverse DNS, backups and templates, and each would otherwise pay
        // its own /vps list call. The module is a container singleton, so the cache
        // lives exactly as long as the request.
        if (array_key_exists($cacheKey, $this->vpsIdCache)) {
            [$via, $ref] = explode('|', $this->vpsIdCache[$cacheKey], 2);

            return ['ref' => $ref, 'via' => $via];
        }

        $rows = $this->vpsRows($params['server']);

        if (!$rows['success']) {
            return ['ref' => null, 'via' => 'none'];
        }

        // Most specific first: the recorded hostname, then the IPs the admin
        // recorded, then the username (API-ordered services use it as the hostname).
        // Without the ordering, a VPS literally named "root" would win over the real
        // one for every WHMCS-imported service.
        $passes = [
            'hostname' => static fn (array $row): bool => $hostname !== '' && strtolower(trim((string) ($row['vps_hostname'] ?? ''))) === $hostname,
            'ip' => static fn (array $row): bool => $ips !== [] && in_array(trim((string) ($row['vps_ip'] ?? '')), $ips, true),
            'username' => static fn (array $row): bool => $username !== '' && strtolower(trim((string) ($row['vps_hostname'] ?? ''))) === $username,
        ];

        foreach ($passes as $via => $matches) {
            foreach ($rows['rows'] as $row) {
                if ($matches($row) && self::refOf($row) !== '') {
                    $ref = self::refOf($row);
                    // Only a successful lookup is cached: a transient API failure must
                    // not pin this VPS to "not found" for the rest of the request.
                    $this->vpsIdCache[$cacheKey] = $via . '|' . $ref;

                    return ['ref' => $ref, 'via' => $via];
                }
            }
        }

        return ['ref' => null, 'via' => 'none'];
    }

    /**
     * The account's VPS rows (GET /vps).
     *
     * @param array<string, mixed> $server
     * @return array{success: bool, message: string, rows: array<int, array<string, mixed>>}
     */
    private function vpsRows(array $server): array
    {
        $decoded = $this->decode($this->call($server, 'GET', '/vps', null));

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message'] !== '' ? $decoded['message'] : 'Could not list the VPSes on the InterServer account.', 'rows' => []];
        }

        $rows = [];

        foreach (is_array($decoded['data']) ? $decoded['data'] : [] as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return ['success' => true, 'message' => '', 'rows' => $rows];
    }

    /**
     * The id to put in `/vps/{id}` paths: the UUID when the list gives one, the
     * legacy integer otherwise.
     *
     * @param array<string, mixed> $row
     */
    private static function refOf(array $row): string
    {
        $uuid = trim((string) ($row['vps_uuid'] ?? ''));

        return $uuid !== '' ? $uuid : trim((string) ($row['vps_id'] ?? ''));
    }

    /**
     * The service's recorded IPs: the primary IP, then the assigned ones (one per line).
     *
     * @param array<string, mixed> $params
     * @return array<int, string>
     */
    private static function serviceIps(array $params): array
    {
        $lines = array_merge(
            [(string) ($params['dedicated_ip'] ?? '')],
            preg_split('/[\s,]+/', (string) ($params['assigned_ips'] ?? '')) ?: []
        );
        $ips = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line !== '' && filter_var($line, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $line;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * postVpsReverseDns takes a bulk map — `{ips: {"<ip>": "<hostname>"}}`.
     * This method used to send a flat `{rdns: "<hostname>"}` instead, which
     * named no IP at all. InterServer applies only the keys that match an IP
     * the VPS actually owns and ignores everything else, so that body
     * updated nothing while still returning 200 — the client saw "Reverse
     * DNS updated successfully" and the PTR never changed. Callers pass an
     * `ips` map; a single `ip` + `rdns` pair is accepted as shorthand.
     */
    public function setReverseDns(array $params): array
    {
        $vpsId = $this->resolveVpsId($params);

        if ($vpsId === null) {
            return ['success' => false, 'message' => self::NOT_LINKED];
        }

        $ips = $params['ips'] ?? null;

        if (!is_array($ips) || $ips === []) {
            $ip = trim((string) ($params['ip'] ?? ''));
            $rdns = trim((string) ($params['rdns'] ?? ''));

            if ($ip === '' || $rdns === '') {
                return ['success' => false, 'message' => 'Both an IP address and a hostname are required to set reverse DNS.'];
            }

            $ips = [$ip => $rdns];
        }

        $response = $this->call($params['server'], 'POST', "/vps/{$vpsId}/reverse_dns", ['ips' => $ips]);

        return $this->toResult($response, 'Reverse DNS updated successfully.');
    }

    /**
     * getVpsReverseDns — current PTR for every IP on the VPS, read live via
     * DNS rather than cached. Response shape is `{ips: {"<ip>": "<ptr>"}}`,
     * with an empty string for IPs that have no PTR set.
     *
     * @return array{success: bool, message: string, ips: array<string, string>}
     */
    public function reverseDnsEntries(array $params): array
    {
        $vpsId = $this->resolveVpsId($params);

        if ($vpsId === null) {
            return ['success' => false, 'message' => self::NOT_LINKED, 'ips' => []];
        }

        $decoded = $this->decode($this->call($params['server'], 'GET', "/vps/{$vpsId}/reverse_dns", null));

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message'], 'ips' => []];
        }

        $ips = [];

        foreach ((array) ($decoded['data']['ips'] ?? []) as $ip => $ptr) {
            $ips[(string) $ip] = (string) $ptr;
        }

        return ['success' => true, 'message' => '', 'ips' => $ips];
    }

    /** @param array<string, mixed> $params */
    private function lifecycleAction(array $params, string $method, string $pathTemplate, string $successMessage = 'OK'): array
    {
        $vpsId = $this->resolveVpsId($params);

        if ($vpsId === null) {
            return ['success' => false, 'message' => self::NOT_LINKED];
        }

        $path = str_replace('{id}', (string) $vpsId, $pathTemplate);
        $response = $this->call($params['server'], $method, $path, $method === 'DELETE' || $method === 'GET' ? null : []);

        return $this->toResult($response, $successMessage);
    }

    /**
     * The `/vps/{id}` ref for this service, or null when it cannot be found. See
     * resolveRemote().
     *
     * @param array<string, mixed> $params
     */
    private function resolveVpsId(array $params): ?string
    {
        return $this->resolveRemote($params)['ref'];
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
