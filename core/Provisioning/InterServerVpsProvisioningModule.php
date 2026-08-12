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

    /** @var array<string, int> hostname => InterServer vps_id, per request */
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
            return ['success' => false, 'message' => 'Could not find this VPS on the hosting provider (hostname lookup failed).', 'backups' => []];
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
            return ['success' => false, 'message' => 'Could not find this VPS on the hosting provider (hostname lookup failed).', 'slices' => []];
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
            return ['success' => false, 'message' => 'Could not find this VPS on the hosting provider (hostname lookup failed).', 'info' => []];
        }

        $decoded = $this->decode($this->call($params['server'], 'GET', "/vps/{$vpsId}", null));

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message'], 'info' => []];
        }

        $data = is_array($decoded['data']) ? $decoded['data'] : [];

        return [
            'success' => true,
            'message' => '',
            'info' => [
                'status' => (string) ($data['vps_status'] ?? ''),
                'hostname' => (string) ($data['vps_hostname'] ?? ''),
                'ip' => (string) ($data['vps_ip'] ?? ''),
                'os' => (string) ($data['vps_os'] ?? ''),
                'slices' => isset($data['vps_slices']) ? (int) $data['vps_slices'] : null,
                'plan' => (string) ($data['services_name'] ?? ''),
            ],
        ];
    }

    public function changePassword(array $params): array
    {
        $vpsId = $this->resolveVpsId($params);

        if ($vpsId === null) {
            return ['success' => false, 'message' => 'Could not find this VPS on the hosting provider (hostname lookup failed).'];
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
            return ['success' => false, 'message' => 'Could not find this VPS on the hosting provider (hostname lookup failed).'];
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
            return ['success' => false, 'message' => 'Could not find this VPS on the hosting provider (hostname lookup failed).'];
        }

        $response = $this->call($params['server'], 'GET', "/vps/{$vpsId}/setup_vnc", null);
        $decoded = $this->decode($response);

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message']];
        }

        if (!$decoded['success']) {
            return ['success' => false, 'message' => $decoded['message']];
        }

        $data = is_array($decoded['data']) ? $decoded['data'] : [];
        $ip = $data['ip'] ?? $data['vnc_ip'] ?? $data['vnc'] ?? null;
        $port = $data['port'] ?? $data['vnc_port'] ?? null;

        if ($ip === null) {
            return [
                'success' => false,
                'message' => 'No VNC console is provisioned for this VPS yet — contact support to provision one.',
            ];
        }

        $url = $port !== null ? "vnc://{$ip}:{$port}" : "vnc://{$ip}";

        return ['success' => true, 'url' => $url, 'message' => 'VNC console connection info retrieved.'];
    }

    public function usage(array $params): array
    {
        $vpsId = $this->resolveVpsId($params);

        if ($vpsId === null) {
            return ['success' => false, 'message' => 'Could not find this VPS on the hosting provider (hostname lookup failed).'];
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
            return ['success' => false, 'message' => 'Could not find this VPS on the hosting provider (hostname lookup failed).', 'templates' => []];
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
     * `localPassword` is the MyAdmin *account* password, re-checked by
     * InterServer on every call because this wipes the disk with no
     * rollback. WHMP stores only `api_username`/`api_token` for a server, so
     * there is nothing to send — this reports that plainly rather than
     * firing a destructive call that would be rejected anyway. The
     * client-facing reinstall flow routes through a support ticket instead.
     */
    public function reinstall(array $params): array
    {
        $vpsId = $this->resolveVpsId($params);

        if ($vpsId === null) {
            return ['success' => false, 'message' => 'Could not find this VPS on the hosting provider (hostname lookup failed).'];
        }

        $template = trim((string) ($params['template'] ?? ''));
        $localPassword = (string) ($params['localPassword'] ?? '');

        if ($template === '') {
            return ['success' => false, 'message' => 'An OS template is required — choose one from the templates this VPS supports.'];
        }

        if ($localPassword === '') {
            return [
                'success' => false,
                'message' => 'The hosting provider requires the control panel account password to reinstall a VPS, which is not stored on this server record. Submit the reinstall as a support request instead.',
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
            return ['success' => false, 'message' => 'Could not find this VPS on the hosting provider (hostname lookup failed).'];
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
            return ['success' => false, 'message' => 'Could not find this VPS on the hosting provider (hostname lookup failed).', 'ips' => []];
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
            return ['success' => false, 'message' => 'Could not find this VPS on the hosting provider (hostname lookup failed).'];
        }

        $path = str_replace('{id}', (string) $vpsId, $pathTemplate);
        $response = $this->call($params['server'], $method, $path, $method === 'DELETE' || $method === 'GET' ? null : []);

        return $this->toResult($response, $successMessage);
    }

    /**
     * Lists the account's VPS services and finds the one whose hostname
     * matches this service — see class docblock.
     *
     * InterServer keys lifecycle endpoints by the numeric `vps_id` it
     * assigns, so this translates our local identifier into theirs. At
     * order time this module stores the locally-generated username as the
     * VPS hostname (see create()), so for app-created services the two are
     * the same string. WHMCS-imported VPS services instead carry the real
     * hostname in `services.hostname` with a generic username like "root" —
     * try both identifiers so both are found.
     *
     * @param array<string, mixed> $params
     */
    private function resolveVpsId(array $params): ?int
    {
        $hostname = (string) ($params['hostname'] ?? '');
        $username = (string) ($params['username'] ?? '');

        // The service's recorded hostname is the authoritative identifier;
        // the username is only a fallback for app-created services where
        // create() stored it as the VPS hostname. Kept in stable order for
        // the cache key.
        $candidates = array_values(array_unique(array_filter(
            [$hostname, $username],
            static fn (string $candidate): bool => $candidate !== ''
        )));

        if ($candidates === []) {
            return null;
        }

        $cacheKey = implode('|', $candidates);

        // Memoized for the life of the request. Every lifecycle call pays a
        // `/vps` list to translate hostname -> numeric id, so rendering a
        // service page that reads status, bandwidth and reverse DNS used to
        // cost three identical list calls on top of the three real ones.
        // The module is a container singleton, so this cache lives exactly
        // as long as the request does.
        if (array_key_exists($cacheKey, $this->vpsIdCache)) {
            return $this->vpsIdCache[$cacheKey];
        }

        $decoded = $this->decode($this->call($params['server'], 'GET', '/vps', null));
        $resolved = null;

        if ($decoded['success'] && is_array($decoded['data'])) {
            // Two passes, most-specific first. Hostname only, then username.
            // Without the ordering a VPS literally named "root" would win
            // over the real one for every WHMCS-imported service.
            foreach ([$hostname, $username] as $preferred) {
                if ($preferred === '') {
                    continue;
                }

                foreach ($decoded['data'] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    if ((string) ($row['vps_hostname'] ?? '') === $preferred) {
                        $resolved = (int) $row['vps_id'];
                        break 2;
                    }
                }
            }
        }

        // Only a successful lookup is cached — a transient API failure must
        // not pin this VPS to "not found" for the rest of the request.
        if ($resolved !== null) {
            $this->vpsIdCache[$cacheKey] = $resolved;
        }

        return $resolved;
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
