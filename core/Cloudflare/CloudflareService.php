<?php

declare(strict_types=1);

namespace CodeVault\Cloudflare;

use CodeVault\Billing\ServiceRepository;
use CodeVault\Database;
use CodeVault\Domains\DomainRepository;
use CodeVault\Provisioning\ServerRepository;
use Throwable;

/**
 * Cloudflare add-on business logic (docs/CLOUDFLARE_ADDON_PLAN.md, Phase 1).
 *
 * Free plan only, every zone in the platform's own Cloudflare account. The rules
 * the owner set:
 *   - clients opt in (a free configurable option at order, or later from the
 *     service page on an eligible product) — never automatic otherwise;
 *   - nameservers change only when the client clicks, and only for domains
 *     registered with us; everyone else gets instructions;
 *   - suspension pauses the zone; termination (or the client turning Cloudflare
 *     off) schedules deletion after a grace period (7 days), undoable until then;
 *   - an existing zone is never adopted — a domain already in the account is
 *     refused, so one customer can never take over another's zone.
 *
 * Every public method returns {ok, message} instead of throwing, so callers
 * (hooks, cron, controllers) can never break provisioning or a page.
 */
final class CloudflareService
{
    public const DNS_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS'];
    public const PROXIABLE = ['A', 'AAAA', 'CNAME'];

    /** Names that must reach the server directly (never proxied): mail, FTP, panels. */
    public const DIRECT_LABELS = ['mail', 'ftp', 'cpanel', 'whm', 'webmail', 'webdisk', 'cpcalendars', 'cpcontacts', 'autodiscover', 'autoconfig', 'smtp', 'imap', 'pop', 'pop3'];

    /** Zone settings a client may change, with the values each accepts. */
    public const SETTINGS = [
        'ssl' => ['off', 'flexible', 'full', 'strict'],
        'always_use_https' => ['on', 'off'],
        'automatic_https_rewrites' => ['on', 'off'],
        'min_tls_version' => ['1.0', '1.1', '1.2', '1.3'],
        'development_mode' => ['on', 'off'],
        'cache_level' => ['basic', 'simplified', 'aggressive'],
        'browser_cache_ttl' => [0, 1800, 3600, 7200, 14400, 28800, 57600, 86400, 172800, 604800, 2592000, 31536000],
        'security_level' => ['essentially_off', 'low', 'medium', 'high', 'under_attack'],
        'browser_check' => ['on', 'off'],
    ];

    public const RULE_MODES = ['block', 'managed_challenge', 'js_challenge', 'challenge', 'whitelist'];
    public const RULE_TARGETS = ['ip', 'ip_range', 'country', 'asn'];

    public const ENDED_STATUSES = ['terminated', 'cancelled'];

    /** Built from the settings on first use; useApi() swaps it in tests. */
    private ?CloudflareApi $api = null;

    public function __construct(
        private readonly CloudflareSettings $settings,
        private readonly CloudflareZoneRepository $zones,
        private readonly ServiceRepository $services,
        private readonly Database $db,
        private readonly ?CloudflareNotifier $notifier = null,
        private readonly ?DomainRepository $domains = null,
        private readonly ?NameserverGateway $nameservers = null,
        private readonly ?ServerRepository $servers = null
    ) {
    }

    // ================================================================ eligibility

    /** The product has the Cloudflare option group attached. */
    public function productEligible(int $productId): bool
    {
        $group = $this->settings->optionGroupId();

        if ($group <= 0 || $productId <= 0) {
            return false;
        }

        return $this->db->selectOne(
            'SELECT 1 AS ok FROM product_configurable_option_groups WHERE product_id = ? AND option_group_id = ? LIMIT 1',
            [$productId, $group]
        ) !== null;
    }

    /** @param array<string, mixed> $service */
    public function choseAtOrder(array $service): bool
    {
        $group = $this->settings->optionGroupId();
        $yes = $this->settings->optionYesId();

        if ($group <= 0 || $yes <= 0 || empty($service['order_id'])) {
            return false;
        }

        $rows = $this->db->select(
            'SELECT configurable_options FROM order_items WHERE order_id = ? AND product_id = ?',
            [(int) $service['order_id'], (int) $service['product_id']]
        );

        foreach ($rows as $row) {
            $options = json_decode((string) ($row['configurable_options'] ?? ''), true);

            if (is_array($options) && (int) ($options[$group] ?? $options[(string) $group] ?? 0) === $yes) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the client's service page may offer.
     *
     * @param array<string, mixed> $service
     * @return array{show: bool, canEnable: bool, zone: array<string, mixed>|null, reason: string}
     */
    public function stateFor(array $service): array
    {
        $zone = $this->zones->liveForService((int) $service['id']);

        if ($zone !== null) {
            return ['show' => true, 'canEnable' => false, 'zone' => $zone, 'reason' => ''];
        }

        if (!$this->settings->connected() || in_array((string) $service['status'], self::ENDED_STATUSES, true)
            || !$this->productEligible((int) $service['product_id'])) {
            return ['show' => false, 'canEnable' => false, 'zone' => null, 'reason' => ''];
        }

        if (self::zoneName((string) ($service['domain'] ?? '')) === null) {
            return ['show' => true, 'canEnable' => false, 'zone' => null, 'reason' => 'This service has no domain name to put on Cloudflare.'];
        }

        if ((string) $service['status'] !== 'active') {
            return ['show' => true, 'canEnable' => false, 'zone' => null, 'reason' => 'Cloudflare can be turned on once the service is active.'];
        }

        if (!$this->settings->allowLater() && !$this->choseAtOrder($service)) {
            return ['show' => false, 'canEnable' => false, 'zone' => null, 'reason' => ''];
        }

        return ['show' => true, 'canEnable' => true, 'zone' => null, 'reason' => ''];
    }

    // ================================================================ lifecycle

    /**
     * Creates the zone for a service: copies the existing DNS (scan), makes sure
     * the site and www point at the hosting server, keeps mail/FTP/panel names
     * unproxied and applies the admin's default settings.
     *
     * @param array{type: string, id: ?int} $actor
     * @return array{ok: bool, message: string, zone?: array<string, mixed>}
     */
    public function enable(int $serviceId, array $actor): array
    {
        $service = $this->services->find($serviceId);

        if ($service === null) {
            return self::fail('Service not found.');
        }

        if (!$this->settings->connected()) {
            return self::fail('Cloudflare is not connected yet. Please contact support.');
        }

        if ((string) $service['status'] !== 'active') {
            return self::fail('Cloudflare can be turned on once the service is active.');
        }

        if ($this->zones->liveForService($serviceId) !== null) {
            return self::fail('Cloudflare is already set up for this service.');
        }

        $name = self::zoneName((string) ($service['domain'] ?? ''));

        if ($name === null) {
            return self::fail('This service has no valid domain name to put on Cloudflare.');
        }

        if ($this->zones->liveByName($name) !== null) {
            return self::fail($name . ' is already using Cloudflare through another service.');
        }

        try {
            $result = $this->api()->createZone($name, $this->settings->accountId());
        } catch (CloudflareApiException $e) {
            return self::fail($e->getMessage());
        }

        $cfId = (string) ($result['id'] ?? '');

        if ($cfId === '') {
            return self::fail('Cloudflare did not return a zone. Please try again.');
        }

        $registered = $this->registeredDomain($name, (int) $service['client_id']);
        $original = array_values(array_filter((array) ($result['original_name_servers'] ?? []), 'is_string'));

        if ($original === [] && $registered !== null) {
            $original = self::decodeNs($registered['nameservers'] ?? null);
        }

        try {
            $zoneId = $this->zones->create([
                'service_id' => $serviceId,
                'client_id' => (int) $service['client_id'],
                'reseller_id' => ($service['client_reseller_id'] ?? null) === null ? null : (int) $service['client_reseller_id'],
                'cf_zone_id' => $cfId,
                'name' => $name,
                'status' => (string) ($result['status'] ?? 'pending'),
                'name_servers' => (array) ($result['name_servers'] ?? []),
                'original_name_servers' => $original,
            ]);
        } catch (Throwable) {
            // Never leave an orphan zone (and use up the pending-zone allowance).
            try {
                $this->api()->deleteZone($cfId);
            } catch (Throwable) {
            }

            return self::fail('Could not save the Cloudflare zone. Please try again.');
        }

        $warnings = [];

        try {
            $scan = $this->api()->scanDns($cfId);
            $this->zones->log($zoneId, 'system', null, 'dns_scan', 'Copied ' . (int) ($scan['recs_added'] ?? 0) . ' existing DNS record(s).');
        } catch (Throwable $e) {
            $warnings[] = 'DNS copy: ' . $e->getMessage();
        }

        try {
            $this->ensureOriginRecords($cfId, $name, $service);
        } catch (Throwable $e) {
            $warnings[] = 'Origin records: ' . $e->getMessage();
        }

        $warnings = array_merge($warnings, $this->applyDefaults($cfId));

        if ($warnings !== []) {
            $this->zones->update($zoneId, ['last_error' => mb_substr(implode(' · ', $warnings), 0, 255)]);
        }

        $this->zones->log($zoneId, $actor['type'], $actor['id'], 'enabled', 'Cloudflare (Free) set up for ' . $name . '.');
        $zone = $this->zones->find($zoneId) ?? [];
        $this->notifier?->pending($zone, $service, $registered !== null);

        return ['ok' => true, 'message' => 'Cloudflare is set up for ' . $name . '. Switch the nameservers to activate it.', 'zone' => $zone];
    }

    /**
     * Checks with Cloudflare whether the nameservers are in place yet.
     *
     * @param array{type: string, id: ?int} $actor
     * @return array{ok: bool, message: string}
     */
    public function checkActivation(int $zoneRowId, array $actor, bool $askRecheck = true): array
    {
        $zone = $this->zones->find($zoneRowId);

        if ($zone === null || $zone['status'] === 'deleted') {
            return self::fail('Zone not found.');
        }

        if ($askRecheck && $zone['status'] !== 'active') {
            try {
                $this->api()->activationCheck((string) $zone['cf_zone_id']);
            } catch (Throwable) {
                // Rate-limited or already active — the status read below still answers.
            }
        }

        $result = $this->refresh($zone);

        if (!$result['ok']) {
            return $result;
        }

        $fresh = $this->zones->find($zoneRowId) ?? $zone;

        if ($fresh['status'] === 'active') {
            return ['ok' => true, 'message' => $fresh['name'] . ' is active on Cloudflare.'];
        }

        return ['ok' => true, 'message' => 'Cloudflare has not seen the new nameservers yet. Changes can take a few hours to spread — we check automatically.'];
    }

    /**
     * Points a domain registered with us at Cloudflare's nameservers. Only ever on
     * the client's own click; the previous nameservers are kept for rollback.
     *
     * @param array{type: string, id: ?int} $actor
     * @return array{ok: bool, message: string}
     */
    public function switchNameservers(int $zoneRowId, array $actor): array
    {
        $zone = $this->zones->find($zoneRowId);

        if ($zone === null || $zone['status'] === 'deleted' || $zone['delete_after'] !== null) {
            return self::fail('Cloudflare is not active for this domain.');
        }

        $domain = $this->registeredDomain((string) $zone['name'], (int) $zone['client_id']);

        if ($domain === null || $this->nameservers === null) {
            return self::fail('This domain is not registered with us — change the nameservers at your registrar.');
        }

        $target = (array) $zone['name_servers'];

        if (count($target) < 2) {
            return self::fail('Cloudflare has not assigned nameservers yet. Please try again shortly.');
        }

        $current = [];

        try {
            $read = $this->nameservers->get((int) $domain['id']);
            $current = ($read['success'] ?? false) ? array_values((array) ($read['nameservers'] ?? [])) : [];
        } catch (Throwable) {
        }

        if ($current === []) {
            $current = self::decodeNs($domain['nameservers'] ?? null);
        }

        if (self::sameNs($current, $target)) {
            $this->zones->update($zoneRowId, ['ns_switched_by_us' => true]);

            return $this->checkActivation($zoneRowId, $actor);
        }

        try {
            $saved = $this->nameservers->save((int) $domain['id'], $target);
        } catch (Throwable $e) {
            $saved = ['success' => false, 'message' => $e->getMessage()];
        }

        if (!($saved['success'] ?? false)) {
            return self::fail('The registrar did not accept the change: ' . (string) ($saved['message'] ?? 'unknown error') . '.');
        }

        $fields = ['ns_switched_by_us' => true];

        if ($current !== [] && !self::sameNs($current, $target)) {
            $fields['original_name_servers'] = $current;
        }

        $this->zones->update($zoneRowId, $fields);
        $this->zones->log($zoneRowId, $actor['type'], $actor['id'], 'ns_switched', 'Nameservers changed to ' . implode(', ', $target) . ' (were ' . (implode(', ', $current) ?: 'unknown') . ').');

        try {
            $this->api()->activationCheck((string) $zone['cf_zone_id']);
        } catch (Throwable) {
        }

        return ['ok' => true, 'message' => 'Nameservers updated. Cloudflare usually activates within a few minutes to a few hours.'];
    }

    /**
     * Turns Cloudflare off: restores the nameservers WHMP switched, then deletes
     * the zone after the grace period (cron). Undo with cancelDeletion().
     *
     * @param array{type: string, id: ?int} $actor
     * @return array{ok: bool, message: string}
     */
    public function scheduleDeletion(int $zoneRowId, string $reason, array $actor): array
    {
        $zone = $this->zones->find($zoneRowId);

        if ($zone === null || $zone['status'] === 'deleted') {
            return self::fail('Zone not found.');
        }

        if ($zone['delete_after'] !== null) {
            return ['ok' => true, 'message' => 'Removal is already scheduled for ' . $zone['delete_after'] . '.'];
        }

        $restored = $this->restoreNameservers($zone, $actor);
        $deleteAfter = date('Y-m-d H:i:s', time() + $this->settings->graceDays() * 86400);
        $this->zones->update($zoneRowId, ['delete_after' => $deleteAfter, 'delete_reason' => mb_substr($reason, 0, 40)]);
        $this->zones->log($zoneRowId, $actor['type'], $actor['id'], 'removal_scheduled', 'Removal scheduled for ' . $deleteAfter . ' (' . $reason . ').' . ($restored ? ' Previous nameservers restored.' : ''));

        $service = $zone['service_id'] !== null ? $this->services->find((int) $zone['service_id']) : null;

        if ($service !== null) {
            $this->notifier?->removalScheduled($zone, $service, self::reasonLabel($reason), $deleteAfter, $restored);
        }

        return ['ok' => true, 'message' => 'Cloudflare will be removed from ' . $zone['name'] . ' on ' . date('j M Y', strtotime($deleteAfter) ?: time()) . '. You can undo this until then.' . ($restored ? ' Your previous nameservers have been restored.' : '')];
    }

    /**
     * @param array{type: string, id: ?int} $actor
     * @return array{ok: bool, message: string}
     */
    public function cancelDeletion(int $zoneRowId, array $actor): array
    {
        $zone = $this->zones->find($zoneRowId);

        if ($zone === null || $zone['status'] === 'deleted' || $zone['delete_after'] === null) {
            return self::fail('There is no scheduled removal to cancel.');
        }

        if ($actor['type'] === 'client' && $zone['service_id'] !== null) {
            $service = $this->services->find((int) $zone['service_id']);

            if ($service === null || (string) $service['status'] !== 'active') {
                return self::fail('Cloudflare can only be kept while the service is active.');
            }
        }

        $this->zones->update($zoneRowId, ['delete_after' => null, 'delete_reason' => null]);
        $this->zones->log($zoneRowId, $actor['type'], $actor['id'], 'removal_cancelled', 'Scheduled removal cancelled.');

        $message = 'Cloudflare will stay on for ' . $zone['name'] . '.';

        if ((int) $zone['ns_switched_by_us'] === 0 && $zone['status'] === 'active') {
            $message .= ' If the nameservers were put back, switch them to Cloudflare again to keep it active.';
        }

        return ['ok' => true, 'message' => $message];
    }

    /**
     * Deletes the zone now, keeping a BIND backup of its DNS first.
     *
     * @param array{type: string, id: ?int} $actor
     * @return array{ok: bool, message: string}
     */
    public function deleteNow(int $zoneRowId, array $actor): array
    {
        $zone = $this->zones->find($zoneRowId);

        if ($zone === null || $zone['status'] === 'deleted') {
            return self::fail('Zone not found.');
        }

        $this->restoreNameservers($zone, $actor);
        $cfId = (string) $zone['cf_zone_id'];
        $backup = null;

        try {
            $backup = $this->api()->exportDns($cfId);
        } catch (Throwable) {
        }

        try {
            $this->api()->deleteZone($cfId);
        } catch (CloudflareApiException $e) {
            if (!self::isGone($e)) {
                $this->zones->update($zoneRowId, ['last_error' => mb_substr('Delete: ' . $e->getMessage(), 0, 255)]);

                return self::fail($e->getMessage());
            }
        }

        $fields = ['status' => 'deleted', 'deleted_at' => date('Y-m-d H:i:s'), 'delete_after' => null, 'paused' => false, 'paused_by_us' => false, 'last_error' => null];

        if ($backup !== null && $backup !== '') {
            $fields['backup_bind'] = $backup;
        }

        $this->zones->update($zoneRowId, $fields);
        $this->zones->log($zoneRowId, $actor['type'], $actor['id'], 'deleted', 'Zone ' . $zone['name'] . ' deleted from Cloudflare' . ($backup ? ' (DNS backup kept).' : '.'));

        return ['ok' => true, 'message' => $zone['name'] . ' has been removed from Cloudflare.'];
    }

    /**
     * @param array{type: string, id: ?int} $actor
     * @return array{ok: bool, message: string}
     */
    public function setPaused(int $zoneRowId, bool $paused, bool $byUs, array $actor): array
    {
        $zone = $this->zones->find($zoneRowId);

        if ($zone === null || $zone['status'] === 'deleted') {
            return self::fail('Zone not found.');
        }

        try {
            $this->api()->setPaused((string) $zone['cf_zone_id'], $paused);
        } catch (CloudflareApiException $e) {
            if (self::isGone($e)) {
                $this->markGone($zone);
            }

            return self::fail($e->getMessage());
        }

        $this->zones->update($zoneRowId, ['paused' => $paused, 'paused_by_us' => $paused && $byUs]);
        $this->zones->log($zoneRowId, $actor['type'], $actor['id'], $paused ? 'paused' : 'resumed', $paused ? 'Cloudflare paused (DNS only, no proxy).' : 'Cloudflare resumed.');

        return ['ok' => true, 'message' => $paused ? 'Cloudflare paused.' : 'Cloudflare resumed.'];
    }

    /**
     * SERVICE_STATUS_CHANGED listener. Never throws.
     */
    public function onServiceStatus(int $serviceId, string $status): void
    {
        try {
            $system = ['type' => 'system', 'id' => null];
            $zone = $this->zones->liveForService($serviceId);

            if ($status === 'active') {
                if ($zone === null) {
                    $service = $this->services->find($serviceId);

                    if ($service !== null && $this->choseAtOrder($service) && !$this->zones->serviceEverHadZone($serviceId)) {
                        $this->enable($serviceId, $system);
                    }

                    return;
                }

                if ((int) $zone['paused_by_us'] === 1) {
                    $this->setPaused((int) $zone['id'], false, false, $system);
                }

                if ($zone['delete_after'] !== null && $zone['delete_reason'] === 'service_ended') {
                    $this->cancelDeletion((int) $zone['id'], $system);
                }

                return;
            }

            if ($zone === null) {
                return;
            }

            if ($status === 'suspended' && (int) $zone['paused'] === 0) {
                $this->setPaused((int) $zone['id'], true, true, $system);
            } elseif (in_array($status, self::ENDED_STATUSES, true)) {
                $this->scheduleDeletion((int) $zone['id'], 'service_ended', $system);
            }
        } catch (Throwable) {
            // A Cloudflare problem must never break provisioning.
        }
    }

    /**
     * Pulls the zone's status from Cloudflare and lines it up with the service:
     * catches suspensions/terminations made without the hook and zones removed in
     * Cloudflare directly.
     *
     * @return array{ok: bool, message: string}
     */
    public function reconcile(int $zoneRowId): array
    {
        $zone = $this->zones->find($zoneRowId);

        if ($zone === null || $zone['status'] === 'deleted') {
            return self::fail('Zone not found.');
        }

        $result = $this->refresh($zone);

        if (!$result['ok']) {
            return $result;
        }

        $zone = $this->zones->find($zoneRowId) ?? $zone;

        if ($zone['status'] === 'deleted') {
            return ['ok' => true, 'message' => 'Zone no longer exists in Cloudflare.'];
        }

        $system = ['type' => 'system', 'id' => null];
        $service = $zone['service_id'] !== null ? $this->services->find((int) $zone['service_id']) : null;
        $status = $service === null ? 'terminated' : (string) $service['status'];

        if (in_array($status, self::ENDED_STATUSES, true) && $zone['delete_after'] === null) {
            $this->scheduleDeletion($zoneRowId, 'service_ended', $system);
        } elseif ($status === 'suspended' && (int) $zone['paused'] === 0) {
            $this->setPaused($zoneRowId, true, true, $system);
        } elseif ($status === 'active' && (int) $zone['paused_by_us'] === 1) {
            $this->setPaused($zoneRowId, false, false, $system);
        }

        return ['ok' => true, 'message' => 'Synced with Cloudflare.'];
    }

    // ================================================================ DNS

    /** @return array{ok: bool, message: string, records?: array<int, array<string, mixed>>} */
    public function dnsRecords(array $zone): array
    {
        try {
            $records = $this->api()->dnsRecords((string) $zone['cf_zone_id']);
            usort($records, static fn (array $a, array $b): int => [$a['type'] ?? '', $a['name'] ?? ''] <=> [$b['type'] ?? '', $b['name'] ?? '']);

            return ['ok' => true, 'message' => 'OK', 'records' => $records];
        } catch (CloudflareApiException $e) {
            return self::fail($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $zone
     * @param array<string, mixed> $input type, name, content, ttl, proxied, priority
     * @param array{type: string, id: ?int} $actor
     * @return array{ok: bool, message: string}
     */
    public function saveDns(array $zone, array $input, ?string $recordId, array $actor): array
    {
        $record = self::validateDns($input, (string) $zone['name'], $error);

        if ($record === null) {
            return self::fail($error);
        }

        try {
            if ($recordId !== null) {
                $existing = $this->findRecord($zone, $recordId);

                if ($existing === null) {
                    return self::fail('That DNS record no longer exists.');
                }

                if (!in_array((string) $existing['type'], self::DNS_TYPES, true)) {
                    return self::fail('This record type can only be deleted here, not edited.');
                }

                $this->api()->updateDns((string) $zone['cf_zone_id'], $recordId, $record);
            } else {
                $this->api()->createDns((string) $zone['cf_zone_id'], $record);
            }
        } catch (CloudflareApiException $e) {
            return self::fail($e->getMessage());
        }

        $this->zones->log((int) $zone['id'], $actor['type'], $actor['id'], $recordId ? 'dns_updated' : 'dns_created', ($recordId ? 'Updated ' : 'Added ') . $record['type'] . ' ' . $record['name'] . ' → ' . mb_substr((string) $record['content'], 0, 80) . (!empty($record['proxied']) ? ' (proxied)' : ''));

        return ['ok' => true, 'message' => 'DNS record ' . ($recordId ? 'updated' : 'added') . '.'];
    }

    /**
     * @param array<string, mixed> $zone
     * @param array{type: string, id: ?int} $actor
     * @return array{ok: bool, message: string}
     */
    public function deleteDns(array $zone, string $recordId, array $actor): array
    {
        try {
            $existing = $this->findRecord($zone, $recordId);

            if ($existing === null) {
                return self::fail('That DNS record no longer exists.');
            }

            $this->api()->deleteDns((string) $zone['cf_zone_id'], $recordId);
        } catch (CloudflareApiException $e) {
            return self::fail($e->getMessage());
        }

        $this->zones->log((int) $zone['id'], $actor['type'], $actor['id'], 'dns_deleted', 'Deleted ' . $existing['type'] . ' ' . $existing['name']);

        return ['ok' => true, 'message' => 'DNS record deleted.'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null the API payload, or null with $error set
     */
    public static function validateDns(array $input, string $zoneName, ?string &$error = null): ?array
    {
        $type = strtoupper(trim((string) ($input['type'] ?? '')));

        if (!in_array($type, self::DNS_TYPES, true)) {
            $error = 'Choose a record type.';

            return null;
        }

        $name = strtolower(trim((string) ($input['name'] ?? ''), " \t."));

        if ($name === '' || $name === '@') {
            $name = $zoneName;
        } elseif ($name !== $zoneName && !str_ends_with($name, '.' . $zoneName)) {
            $name .= '.' . $zoneName;
        }

        if (strlen($name) > 253 || preg_match('/^(\*\.)?([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $name) !== 1) {
            $error = 'The record name is not valid.';

            return null;
        }

        $content = trim((string) ($input['content'] ?? ''));

        $valid = match ($type) {
            'A' => filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            'AAAA' => filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
            'CNAME', 'MX', 'NS' => self::isHostname($content = strtolower(rtrim($content, '.'))),
            'TXT' => $content !== '' && strlen($content) <= 2048,
        };

        if (!$valid) {
            $error = match ($type) {
                'A' => 'Enter a valid IPv4 address.',
                'AAAA' => 'Enter a valid IPv6 address.',
                'TXT' => 'Enter the text value (up to 2,048 characters).',
                default => 'Enter a valid hostname.',
            };

            return null;
        }

        $ttl = (int) ($input['ttl'] ?? 1);
        $ttl = $ttl === 1 ? 1 : max(60, min(86400, $ttl));
        $record = ['type' => $type, 'name' => $name, 'content' => $content, 'ttl' => $ttl];

        if (in_array($type, self::PROXIABLE, true)) {
            $record['proxied'] = !empty($input['proxied']) && $input['proxied'] !== '0';
        }

        if ($type === 'MX') {
            $priority = (int) ($input['priority'] ?? 10);

            if ($priority < 0 || $priority > 65535) {
                $error = 'MX priority must be between 0 and 65535.';

                return null;
            }

            $record['priority'] = $priority;
        }

        return $record;
    }

    // ================================================================ settings, cache, rules

    /** @return array{ok: bool, message: string, settings?: array<string, mixed>} */
    public function zoneSettings(array $zone): array
    {
        try {
            return ['ok' => true, 'message' => 'OK', 'settings' => $this->api()->settings((string) $zone['cf_zone_id'])];
        } catch (CloudflareApiException $e) {
            return self::fail($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $zone
     * @param array{type: string, id: ?int} $actor
     * @return array{ok: bool, message: string}
     */
    public function changeSetting(array $zone, string $setting, string $value, array $actor): array
    {
        if (!array_key_exists($setting, self::SETTINGS)) {
            return self::fail('That setting cannot be changed here.');
        }

        $allowed = self::SETTINGS[$setting];
        $typed = $setting === 'browser_cache_ttl' ? (int) $value : $value;

        if (!in_array($typed, $allowed, true)) {
            return self::fail('Choose one of the listed values.');
        }

        try {
            $this->api()->setSetting((string) $zone['cf_zone_id'], $setting, $typed);
        } catch (CloudflareApiException $e) {
            return self::fail($e->getMessage());
        }

        $this->zones->log((int) $zone['id'], $actor['type'], $actor['id'], 'setting', self::settingLabel($setting) . ' → ' . $value);

        return ['ok' => true, 'message' => self::settingLabel($setting) . ' updated.'];
    }

    /**
     * @param array<string, mixed> $zone
     * @param array<int, string>|null $urls null = everything
     * @param array{type: string, id: ?int} $actor
     * @return array{ok: bool, message: string}
     */
    public function purge(array $zone, ?array $urls, array $actor): array
    {
        try {
            if ($urls === null) {
                $this->api()->purgeEverything((string) $zone['cf_zone_id']);
                $summary = 'Purged the whole cache.';
            } else {
                $clean = [];

                foreach ($urls as $url) {
                    $url = trim($url);

                    if ($url === '') {
                        continue;
                    }

                    $host = strtolower((string) parse_url($url, PHP_URL_HOST));

                    if (!preg_match('#^https?://#i', $url) || ($host !== $zone['name'] && !str_ends_with($host, '.' . $zone['name']))) {
                        return self::fail('Each URL must start with http:// or https:// and be on ' . $zone['name'] . '.');
                    }

                    $clean[] = $url;
                }

                if ($clean === [] || count($clean) > 30) {
                    return self::fail('Enter between 1 and 30 URLs, one per line.');
                }

                $this->api()->purgeUrls((string) $zone['cf_zone_id'], $clean);
                $summary = 'Purged ' . count($clean) . ' URL(s).';
            }
        } catch (CloudflareApiException $e) {
            return self::fail($e->getMessage());
        }

        $this->zones->log((int) $zone['id'], $actor['type'], $actor['id'], 'purge', $summary);

        return ['ok' => true, 'message' => $summary];
    }

    /** @return array{ok: bool, message: string, rules?: array<int, array<string, mixed>>} */
    public function accessRules(array $zone): array
    {
        try {
            return ['ok' => true, 'message' => 'OK', 'rules' => $this->api()->accessRules((string) $zone['cf_zone_id'])];
        } catch (CloudflareApiException $e) {
            return self::fail($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $zone
     * @param array{type: string, id: ?int} $actor
     * @return array{ok: bool, message: string}
     */
    public function addAccessRule(array $zone, string $mode, string $target, string $value, string $notes, array $actor): array
    {
        if (!in_array($mode, self::RULE_MODES, true) || !in_array($target, self::RULE_TARGETS, true)) {
            return self::fail('Choose an action and a target.');
        }

        $value = trim($value);

        $valid = match ($target) {
            'ip' => filter_var($value, FILTER_VALIDATE_IP) !== false,
            'ip_range' => self::isCidr($value),
            'country' => preg_match('/^[A-Za-z]{2}$/', $value) === 1 && ($value = strtoupper($value)) !== '',
            'asn' => preg_match('/^(AS)?\d{1,10}$/i', $value) === 1 && ($value = 'AS' . preg_replace('/^AS/i', '', $value)) !== '',
        };

        if (!$valid) {
            return self::fail(match ($target) {
                'ip' => 'Enter a valid IP address.',
                'ip_range' => 'Enter a range like 203.0.113.0/24 (IPv4 /16 or /24, IPv6 /32, /48 or /64).',
                'country' => 'Enter a two-letter country code, e.g. NG.',
                default => 'Enter an AS number, e.g. AS13335.',
            });
        }

        try {
            $this->api()->createAccessRule((string) $zone['cf_zone_id'], $mode, $target, $value, mb_substr(trim($notes), 0, 100));
        } catch (CloudflareApiException $e) {
            return self::fail($e->getMessage());
        }

        $this->zones->log((int) $zone['id'], $actor['type'], $actor['id'], 'rule_added', 'IP rule: ' . $mode . ' ' . $target . ' ' . $value);

        return ['ok' => true, 'message' => 'Rule added.'];
    }

    /**
     * @param array<string, mixed> $zone
     * @param array{type: string, id: ?int} $actor
     * @return array{ok: bool, message: string}
     */
    public function deleteAccessRule(array $zone, string $ruleId, array $actor): array
    {
        try {
            $this->api()->deleteAccessRule((string) $zone['cf_zone_id'], $ruleId);
        } catch (CloudflareApiException $e) {
            return self::fail($e->getMessage());
        }

        $this->zones->log((int) $zone['id'], $actor['type'], $actor['id'], 'rule_deleted', 'IP rule removed.');

        return ['ok' => true, 'message' => 'Rule removed.'];
    }

    // ================================================================ helpers

    /** @return array<string, mixed>|null the domain row when registered with us by this client */
    public function registeredDomain(string $name, int $clientId): ?array
    {
        if ($this->domains === null) {
            return null;
        }

        try {
            $domain = $this->domains->findByName($name);
        } catch (Throwable) {
            return null;
        }

        if ($domain === null || (int) $domain['client_id'] !== $clientId || !in_array((string) $domain['status'], ['active', 'grace'], true)) {
            return null;
        }

        return $domain;
    }

    /** Normalises a service's domain to the zone name, or null when it is not one. */
    public static function zoneName(string $domain): ?string
    {
        $name = strtolower(trim($domain, " \t\n\r\0\x0B."));
        $name = preg_replace('#^https?://#', '', $name) ?? $name;
        $name = explode('/', $name)[0];

        if (str_starts_with($name, 'www.')) {
            $name = substr($name, 4);
        }

        if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7F]/', $name)) {
            $name = (string) (idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $name);
        }

        if (!str_contains($name, '.') || !self::isHostname($name) || filter_var($name, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        return $name;
    }

    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'service_ended' => 'the hosting service has ended',
            'client' => 'you turned it off',
            'admin' => 'turned off by our team',
            default => $reason,
        };
    }

    public static function settingLabel(string $setting): string
    {
        return match ($setting) {
            'ssl' => 'SSL/TLS mode',
            'always_use_https' => 'Always use HTTPS',
            'automatic_https_rewrites' => 'Automatic HTTPS rewrites',
            'min_tls_version' => 'Minimum TLS version',
            'development_mode' => 'Development mode',
            'cache_level' => 'Caching level',
            'browser_cache_ttl' => 'Browser cache TTL',
            'security_level' => 'Security level',
            'browser_check' => 'Browser integrity check',
            default => $setting,
        };
    }

    /** For tests: swap the API client. */
    public function useApi(CloudflareApi $api): void
    {
        $this->api = $api;
    }

    private function api(): CloudflareApi
    {
        return $this->api ??= $this->settings->api();
    }

    /**
     * Reads the zone from Cloudflare and stores status / nameservers / paused.
     *
     * @param array<string, mixed> $zone
     * @return array{ok: bool, message: string}
     */
    private function refresh(array $zone): array
    {
        $now = date('Y-m-d H:i:s');

        try {
            $remote = $this->api()->zone((string) $zone['cf_zone_id']);
        } catch (CloudflareApiException $e) {
            if (self::isGone($e)) {
                $this->markGone($zone);

                return ['ok' => true, 'message' => 'The zone no longer exists in Cloudflare.'];
            }

            // Stamped as synced too, so a revoked token or an outage is retried
            // on the next daily pass instead of every cron run.
            $this->zones->update((int) $zone['id'], ['last_checked_at' => $now, 'last_synced_at' => $now, 'last_error' => mb_substr($e->getMessage(), 0, 255)]);

            return self::fail($e->getMessage());
        }

        $status = (string) ($remote['status'] ?? $zone['status']);
        $fields = [
            'status' => $status,
            'paused' => (bool) ($remote['paused'] ?? false),
            'last_checked_at' => $now,
            'last_synced_at' => $now,
        ];

        if (!empty($remote['name_servers'])) {
            $fields['name_servers'] = (array) $remote['name_servers'];
        }

        if (!(bool) ($remote['paused'] ?? false)) {
            $fields['paused_by_us'] = false;
        }

        $becameActive = $status === 'active' && $zone['status'] !== 'active';

        if ($becameActive) {
            $fields['activated_at'] = $now;
            $fields['last_error'] = null;
        }

        $this->zones->update((int) $zone['id'], $fields);

        if ($becameActive) {
            $this->zones->log((int) $zone['id'], 'system', null, 'activated', $zone['name'] . ' is active on Cloudflare.');
            $service = $zone['service_id'] !== null ? $this->services->find((int) $zone['service_id']) : null;

            if ($service !== null) {
                $this->notifier?->active($this->zones->find((int) $zone['id']) ?? $zone, $service);
            }
        }

        return ['ok' => true, 'message' => 'OK'];
    }

    /** @param array<string, mixed> $zone */
    private function markGone(array $zone): void
    {
        $this->zones->update((int) $zone['id'], ['status' => 'deleted', 'deleted_at' => date('Y-m-d H:i:s'), 'delete_after' => null, 'paused' => false, 'paused_by_us' => false]);
        $this->zones->log((int) $zone['id'], 'system', null, 'gone', 'The zone was removed in Cloudflare (or expired while pending).');
    }

    /**
     * Puts back the nameservers WHMP switched. Best-effort.
     *
     * @param array<string, mixed> $zone
     * @param array{type: string, id: ?int} $actor
     */
    private function restoreNameservers(array $zone, array $actor): bool
    {
        if ((int) $zone['ns_switched_by_us'] !== 1 || $this->nameservers === null) {
            return false;
        }

        $original = array_values(array_filter((array) $zone['original_name_servers'], static fn ($ns): bool => is_string($ns) && !str_ends_with(strtolower($ns), '.ns.cloudflare.com')));
        $domain = $this->registeredDomain((string) $zone['name'], (int) $zone['client_id']);

        if (count($original) < 2 || $domain === null) {
            $this->zones->log((int) $zone['id'], 'system', null, 'ns_restore_skipped', 'Could not restore nameservers automatically (no previous nameservers on record).');

            return false;
        }

        try {
            $saved = $this->nameservers->save((int) $domain['id'], $original);
        } catch (Throwable $e) {
            $saved = ['success' => false, 'message' => $e->getMessage()];
        }

        if (!($saved['success'] ?? false)) {
            $this->zones->log((int) $zone['id'], 'system', null, 'ns_restore_failed', 'Restoring nameservers failed: ' . mb_substr((string) ($saved['message'] ?? ''), 0, 150));

            return false;
        }

        $this->zones->update((int) $zone['id'], ['ns_switched_by_us' => false]);
        $this->zones->log((int) $zone['id'], $actor['type'], $actor['id'], 'ns_restored', 'Nameservers restored to ' . implode(', ', $original) . '.');

        return true;
    }

    /**
     * Makes sure the site and www resolve to the hosting server through
     * Cloudflare, and that mail/FTP/panel names stay direct (unproxied).
     *
     * @param array<string, mixed> $service
     */
    private function ensureOriginRecords(string $cfId, string $name, array $service): void
    {
        $records = $this->api()->dnsRecords($cfId);
        $ip = $this->originIp($service);
        $byName = [];

        foreach ($records as $record) {
            $byName[strtolower((string) ($record['name'] ?? ''))][] = $record;
        }

        $webTypes = ['A', 'AAAA', 'CNAME'];
        $hasWeb = static fn (string $host): bool => array_filter($byName[$host] ?? [], static fn (array $r): bool => in_array($r['type'] ?? '', $webTypes, true)) !== [];

        if ($ip !== null && !$hasWeb($name)) {
            $this->api()->createDns($cfId, ['type' => 'A', 'name' => $name, 'content' => $ip, 'ttl' => 1, 'proxied' => true]);
        }

        if (!$hasWeb('www.' . $name)) {
            $this->api()->createDns($cfId, ['type' => 'CNAME', 'name' => 'www.' . $name, 'content' => $name, 'ttl' => 1, 'proxied' => true]);
        }

        $direct = [];

        foreach (self::DIRECT_LABELS as $label) {
            $direct[$label . '.' . $name] = true;
        }

        foreach ($records as $record) {
            if (($record['type'] ?? '') === 'MX') {
                $direct[strtolower(rtrim((string) ($record['content'] ?? ''), '.'))] = true;
            }
        }

        foreach ($records as $record) {
            $host = strtolower((string) ($record['name'] ?? ''));

            if (!empty($record['proxied']) && isset($direct[$host]) && isset($record['id'])) {
                $this->api()->updateDns($cfId, (string) $record['id'], ['proxied' => false]);
            }
        }
    }

    /** @return array<int, string> warnings */
    private function applyDefaults(string $cfId): array
    {
        $warnings = [];
        $defaults = [
            'ssl' => $this->settings->defaultSsl(),
            'always_use_https' => $this->settings->defaultAlwaysHttps() ? 'on' : 'off',
            'security_level' => $this->settings->defaultSecurityLevel(),
        ];

        foreach ($defaults as $setting => $value) {
            try {
                $this->api()->setSetting($cfId, $setting, $value);
            } catch (Throwable $e) {
                $warnings[] = self::settingLabel($setting) . ': ' . $e->getMessage();
            }
        }

        return $warnings;
    }

    /** @param array<string, mixed> $service */
    private function originIp(array $service): ?string
    {
        $dedicated = trim((string) ($service['dedicated_ip'] ?? ''));

        if (filter_var($dedicated, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $dedicated;
        }

        if ($this->servers === null || empty($service['server_id'])) {
            return null;
        }

        try {
            $server = $this->servers->find((int) $service['server_id']);
        } catch (Throwable) {
            return null;
        }

        $host = trim((string) ($server['hostname'] ?? ''));

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }

        if ($host === '' || !self::isHostname(strtolower($host))) {
            return null;
        }

        $resolved = @gethostbyname($host);

        return filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $resolved : null;
    }

    /**
     * @param array<string, mixed> $zone
     * @return array<string, mixed>|null
     */
    private function findRecord(array $zone, string $recordId): ?array
    {
        foreach ($this->api()->dnsRecords((string) $zone['cf_zone_id']) as $record) {
            if ((string) ($record['id'] ?? '') === $recordId) {
                return $record;
            }
        }

        return null;
    }

    private static function isGone(CloudflareApiException $e): bool
    {
        return $e->httpStatus === 404 || $e->hasCode(1001) || $e->hasCode(1003) || $e->hasCode(7003);
    }

    private static function isHostname(string $host): bool
    {
        return strlen($host) <= 253
            && preg_match('/^([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $host) === 1;
    }

    private static function isCidr(string $value): bool
    {
        if (!str_contains($value, '/')) {
            return false;
        }

        [$ip, $bits] = explode('/', $value, 2);

        if (!ctype_digit($bits)) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return in_array((int) $bits, [16, 24], true);
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && in_array((int) $bits, [32, 48, 64], true);
    }

    /** @return array<int, string> */
    private static function decodeNs(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }

        $decoded = json_decode((string) $value, true);

        if (is_array($decoded)) {
            return array_values(array_filter($decoded, static fn ($v): bool => is_string($v) && $v !== ''));
        }

        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $value) ?: [])));
    }

    /**
     * @param array<int, string> $a
     * @param array<int, string> $b
     */
    private static function sameNs(array $a, array $b): bool
    {
        $norm = static function (array $list): array {
            $list = array_map(static fn ($ns): string => strtolower(rtrim(trim((string) $ns), '.')), $list);
            sort($list);

            return $list;
        };

        return $a !== [] && $norm($a) === $norm($b);
    }

    /** @return array{ok: false, message: string} */
    private static function fail(?string $message): array
    {
        return ['ok' => false, 'message' => (string) ($message ?? 'Something went wrong.')];
    }
}
