<?php

declare(strict_types=1);

namespace CodeVault\Cloudflare;

use CodeVault\Database;

/**
 * cloudflare_zones + cloudflare_activity (migration 0216).
 *
 * A "live" zone is any row not yet deleted. A service has at most one live zone;
 * deleted rows are history (and keep their BIND backup).
 */
final class CloudflareZoneRepository
{
    public const LIVE = "status <> 'deleted'";

    private const DETAIL_SELECT = 'SELECT z.*, c.first_name, c.last_name, c.email AS client_email, r.brand_name AS store_name, r.slug AS store_slug,
             r.client_id AS store_owner_client_id, s.status AS service_status, s.product_name
             FROM cloudflare_zones z
             LEFT JOIN clients c ON c.id = z.client_id
             LEFT JOIN resellers r ON r.id = z.reseller_id
             LEFT JOIN services s ON s.id = z.service_id';

    /** Columns update() may write. */
    private const WRITABLE = [
        'service_id', 'status', 'name_servers', 'original_name_servers', 'ns_switched_by_us', 'paused', 'paused_by_us',
        'delete_after', 'delete_reason', 'backup_bind', 'last_error', 'reminders_sent', 'activated_at', 'last_checked_at',
        'last_synced_at', 'deleted_at', 'dnssec_status', 'dnssec_ds', 'dnssec_ds_by_us', 'dnssec_ds_removed',
        'ns_restore_after', 'dnssec_disable_after', 'origin_cert_id', 'origin_cert_expires',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return self::decode($this->db->selectOne('SELECT * FROM cloudflare_zones WHERE id = ?', [$id]));
    }

    /** @return array<string, mixed>|null any WHMP history row for this Cloudflare zone id */
    public function byCloudflareId(string $cloudflareId): ?array
    {
        return self::decode($this->db->selectOne('SELECT * FROM cloudflare_zones WHERE cf_zone_id = ? LIMIT 1', [$cloudflareId]));
    }

    /** @return array<string, mixed>|null the zone with its owner, store and service (admin) */
    public function detailed(int $id): ?array
    {
        return self::decode($this->db->selectOne(self::DETAIL_SELECT . ' WHERE z.id = ?', [$id]));
    }

    /** @return array<string, mixed>|null the service's live zone */
    public function liveForService(int $serviceId): ?array
    {
        return self::decode($this->db->selectOne(
            'SELECT * FROM cloudflare_zones WHERE service_id = ? AND ' . self::LIVE . ' ORDER BY id DESC LIMIT 1',
            [$serviceId]
        ));
    }

    /** True once a service has ever had a zone — automatic enrolment happens only once. */
    public function serviceEverHadZone(int $serviceId): bool
    {
        return $this->db->selectOne('SELECT id FROM cloudflare_zones WHERE service_id = ? LIMIT 1', [$serviceId]) !== null;
    }

    /** @return array<string, mixed>|null a live zone for this domain name, whoever owns it */
    public function liveByName(string $name): ?array
    {
        return self::decode($this->db->selectOne(
            'SELECT * FROM cloudflare_zones WHERE name = ? AND ' . self::LIVE . ' ORDER BY id DESC LIMIT 1',
            [strtolower($name)]
        ));
    }

    /** @param array<string, mixed> $row */
    public function create(array $row): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO cloudflare_zones (service_id, client_id, reseller_id, cf_zone_id, name, status, name_servers, original_name_servers, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $row['service_id'] ?? null,
                (int) $row['client_id'],
                $row['reseller_id'] ?? null,
                (string) $row['cf_zone_id'],
                strtolower((string) $row['name']),
                (string) ($row['status'] ?? 'pending'),
                json_encode(array_values((array) ($row['name_servers'] ?? []))),
                json_encode(array_values((array) ($row['original_name_servers'] ?? []))),
                $now,
                $now,
            ]
        );
    }

    /**
     * Persists a zone explicitly imported from the connected Cloudflare account.
     * It is linked to an existing, opted-in service and remains externally
     * nameserver-managed until the client chooses otherwise.
     *
     * @param array<string, mixed> $row
     */
    public function createImported(array $row): int
    {
        $now = date('Y-m-d H:i:s');
        $dnssecDs = is_array($row['dnssec_ds'] ?? null) ? json_encode($row['dnssec_ds']) : null;

        return (int) $this->db->insert(
            'INSERT INTO cloudflare_zones (service_id, client_id, reseller_id, cf_zone_id, name, status, name_servers, original_name_servers, ns_switched_by_us, paused, paused_by_us, last_checked_at, last_synced_at, dnssec_status, dnssec_ds, dnssec_ds_by_us, dnssec_ds_removed, last_error, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 0, ?, ?, ?, ?, 0, 0, ?, ?, ?)',
            [
                (int) $row['service_id'],
                (int) $row['client_id'],
                $row['reseller_id'] ?? null,
                (string) $row['cf_zone_id'],
                strtolower((string) $row['name']),
                (string) ($row['status'] ?? 'pending'),
                json_encode(array_values((array) ($row['name_servers'] ?? []))),
                json_encode(array_values((array) ($row['original_name_servers'] ?? []))),
                !empty($row['paused']) ? 1 : 0,
                $now,
                $now,
                isset($row['dnssec_status']) ? mb_substr((string) $row['dnssec_status'], 0, 20) : null,
                $dnssecDs,
                isset($row['last_error']) ? mb_substr((string) $row['last_error'], 0, 255) : null,
                $now,
                $now,
            ]
        );
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): void
    {
        $sets = [];
        $bindings = [];

        foreach ($fields as $column => $value) {
            if (!in_array($column, self::WRITABLE, true)) {
                continue;
            }

            if (in_array($column, ['name_servers', 'original_name_servers'], true) && is_array($value)) {
                $value = json_encode(array_values($value));
            }

            if ($column === 'dnssec_ds' && is_array($value)) {
                $value = json_encode($value);
            }

            if (is_bool($value)) {
                $value = $value ? 1 : 0;
            }

            $sets[] = $column . ' = ?';
            $bindings[] = $value;
        }

        if ($sets === []) {
            return;
        }

        $sets[] = 'updated_at = ?';
        $bindings[] = date('Y-m-d H:i:s');
        $bindings[] = $id;

        $this->db->update('UPDATE cloudflare_zones SET ' . implode(', ', $sets) . ' WHERE id = ?', $bindings);
    }

    /** @return array<int, array<string, mixed>> zones still waiting for nameservers */
    public function pending(int $limit = 200): array
    {
        return array_map([self::class, 'decode'], $this->db->select(
            "SELECT * FROM cloudflare_zones WHERE status IN ('pending', 'initializing') AND delete_after IS NULL ORDER BY last_checked_at IS NOT NULL, last_checked_at ASC LIMIT " . max(1, $limit)
        ));
    }

    /** @return array<int, array<string, mixed>> zones whose grace period has run out */
    public function dueForDeletion(string $now, int $limit = 50): array
    {
        return array_map([self::class, 'decode'], $this->db->select(
            'SELECT * FROM cloudflare_zones WHERE delete_after IS NOT NULL AND delete_after <= ? AND ' . self::LIVE . ' ORDER BY delete_after ASC LIMIT ' . max(1, $limit),
            [$now]
        ));
    }

    /** Deferred DNSSEC safety action, limited to two internal date columns. */
    public function dueFor(string $column, string $now, int $limit = 50): array
    {
        if (!in_array($column, ['ns_restore_after', 'dnssec_disable_after'], true)) {
            return [];
        }

        return array_map([self::class, 'decode'], $this->db->select(
            'SELECT * FROM cloudflare_zones WHERE ' . $column . ' IS NOT NULL AND ' . $column . ' <= ? AND ' . self::LIVE . ' ORDER BY ' . $column . ' ASC LIMIT ' . max(1, $limit),
            [$now]
        ));
    }

    /** @return array<int, array<string, mixed>> live zones, least recently synced first */
    public function staleLive(string $before, int $limit = 100): array
    {
        return array_map([self::class, 'decode'], $this->db->select(
            'SELECT * FROM cloudflare_zones WHERE ' . self::LIVE . ' AND (last_synced_at IS NULL OR last_synced_at < ?) ORDER BY last_synced_at IS NOT NULL, last_synced_at ASC LIMIT ' . max(1, $limit),
            [$before]
        ));
    }

    /**
     * Admin list, with the owner. Store customers carry their store's name so the
     * admin screen can label them without linking to /admin/clients.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(?string $status, string $q, int $limit = 50, int $offset = 0): array
    {
        [$where, $bindings] = self::filters($status, $q);

        return array_map([self::class, 'decode'], $this->db->select(
            self::DETAIL_SELECT . ' WHERE ' . $where . ' ORDER BY z.id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $bindings
        ));
    }

    /** @return array<string, int> status => count, plus 'deleting' for zones in their grace period */
    public function counts(): array
    {
        $out = ['active' => 0, 'pending' => 0, 'paused' => 0, 'deleting' => 0, 'deleted' => 0];

        foreach ($this->db->select('SELECT status, paused, delete_after IS NOT NULL AS deleting, COUNT(*) AS n FROM cloudflare_zones GROUP BY status, paused, deleting') as $row) {
            $n = (int) $row['n'];
            $status = (string) $row['status'];

            if ($status === 'deleted') {
                $out['deleted'] += $n;
            } elseif ((int) $row['deleting'] === 1) {
                $out['deleting'] += $n;
            } elseif ((int) $row['paused'] === 1) {
                $out['paused'] += $n;
            } elseif ($status === 'active') {
                $out['active'] += $n;
            } else {
                $out['pending'] += $n;
            }
        }

        return $out;
    }

    public function log(int $zoneId, string $actorType, ?int $actorId, string $action, string $summary): void
    {
        $this->db->insert(
            'INSERT INTO cloudflare_activity (zone_id, actor_type, actor_id, action, summary, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$zoneId, $actorType, $actorId, mb_substr($action, 0, 60), mb_substr($summary, 0, 255), date('Y-m-d H:i:s')]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function activity(int $zoneId, int $limit = 50): array
    {
        return $this->db->select(
            'SELECT * FROM cloudflare_activity WHERE zone_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit),
            [$zoneId]
        );
    }

    /** @return array{0: string, 1: array<int, mixed>} */
    private static function filters(?string $status, string $q): array
    {
        $where = ['1 = 1'];
        $bindings = [];

        match ($status) {
            'active' => $where[] = "z.status = 'active' AND z.paused = 0 AND z.delete_after IS NULL",
            'pending' => $where[] = "z.status IN ('pending', 'initializing') AND z.delete_after IS NULL",
            'paused' => $where[] = "z.paused = 1 AND z.status <> 'deleted'",
            'deleting' => $where[] = "z.delete_after IS NOT NULL AND z.status <> 'deleted'",
            'deleted' => $where[] = "z.status = 'deleted'",
            'live' => $where[] = "z.status <> 'deleted'",
            default => null,
        };

        $q = trim($q);

        if ($q !== '') {
            $where[] = '(z.name LIKE ? OR c.email LIKE ? OR z.service_id = ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($bindings, $like, $like, ctype_digit($q) ? (int) $q : 0);
        }

        return [implode(' AND ', $where), $bindings];
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    private static function decode(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        foreach (['name_servers', 'original_name_servers'] as $column) {
            $decoded = json_decode((string) ($row[$column] ?? ''), true);
            $row[$column] = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        }

        $ds = json_decode((string) ($row['dnssec_ds'] ?? ''), true);
        $row['dnssec_ds'] = is_array($ds) ? $ds : null;
        $row['dnssec_status'] = isset($row['dnssec_status']) ? (string) $row['dnssec_status'] : null;
        $row['dnssec_ds_by_us'] = (int) ($row['dnssec_ds_by_us'] ?? 0);
        $row['dnssec_ds_removed'] = (int) ($row['dnssec_ds_removed'] ?? 0);
        $row['origin_cert_id'] = !empty($row['origin_cert_id']) ? (string) $row['origin_cert_id'] : null;
        $row['origin_cert_expires'] = $row['origin_cert_expires'] ?? null;
        $row['ns_restore_after'] = $row['ns_restore_after'] ?? null;
        $row['dnssec_disable_after'] = $row['dnssec_disable_after'] ?? null;

        return $row;
    }
}
