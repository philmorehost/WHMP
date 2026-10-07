<?php

declare(strict_types=1);

namespace CodeVault\UsernameChanger;

use CodeVault\Database;
use CodeVault\Provisioning\ProvisioningService;
use DateTimeImmutable;

/**
 * Is a username free? Two speeds:
 *
 *  - fast()  — the live check behind the modal. Local rules plus three indexed
 *              lookups (services.username, open requests, the cron-refreshed copy
 *              of every WHM account on the server). No network, no writes: it
 *              answers in about a millisecond of database time, so the modal can
 *              ask on every pause in typing without the client ever feeling it.
 *  - deep()  — fast() plus WHM's own `verify_new_username`. Run once at submit and
 *              again just before the rename (plan §5 rules 8–9).
 *
 * Neither ever returns WHO holds a name — only whether it is free.
 */
final class UsernameAvailability
{
    /** How old the server account copy may get before cron refreshes it. */
    public const ACCOUNT_CACHE_MINUTES = 15;

    public function __construct(
        private readonly Database $db,
        private readonly UsernameChangerSettings $settings,
        private readonly ?ProvisioningService $provisioning = null
    ) {
    }

    /**
     * @param array<string, mixed> $service id, server_id, username
     * @return array{ok: bool, code: string, message: string}
     */
    public function fast(string $name, array $service): array
    {
        $name = UsernamePolicy::normalise($name);
        $violation = $this->settings->policy()->violation($name, (string) ($service['username'] ?? ''));

        if ($violation !== null) {
            return ['ok' => false, 'code' => $violation[0], 'message' => $violation[1]];
        }

        $taken = $this->takenAmong([$name], $service);

        if (isset($taken[$name])) {
            return ['ok' => false, 'code' => $taken[$name], 'message' => self::takenMessage($taken[$name])];
        }

        return ['ok' => true, 'code' => 'ok', 'message' => 'Available.'];
    }

    /**
     * fast() and then the server itself. A server that cannot be reached is
     * reported as such (`unverified`), never as "available".
     *
     * @param array<string, mixed> $service
     * @return array{ok: bool, code: string, message: string}
     */
    public function deep(string $name, array $service): array
    {
        $fast = $this->fast($name, $service);

        if (!$fast['ok'] || $this->provisioning === null) {
            return $fast;
        }

        $whm = $this->provisioning->verifyNewUsername((int) $service['id'], UsernamePolicy::normalise($name));

        if (!$whm['reachable']) {
            return ['ok' => false, 'code' => 'unverified', 'message' => 'We could not reach the hosting server to confirm this name. Please try again in a few minutes.'];
        }

        if (!$whm['available']) {
            return ['ok' => false, 'code' => 'server', 'message' => self::friendlyServerReason((string) $whm['message'])];
        }

        return $fast;
    }

    /**
     * Which of $names are taken, as [name => reason code]. One query per
     * source regardless of how many names, so suggestions cost the same as a
     * single check.
     *
     * @param array<int, string>   $names already normalised
     * @param array<string, mixed> $service
     * @return array<string, string>
     */
    public function takenAmong(array $names, array $service): array
    {
        $names = array_values(array_unique(array_filter($names, static fn ($n) => $n !== '')));

        if ($names === []) {
            return [];
        }

        $serviceId = (int) ($service['id'] ?? 0);
        $serverId = ($service['server_id'] ?? null) === null ? null : (int) $service['server_id'];
        $in = UsernameChangeRepository::placeholders($names);
        $taken = [];

        // 1. Another service already has it (on any server, or on this one).
        $sql = "SELECT LOWER(username) AS u FROM services WHERE username IN ({$in}) AND id <> ? AND status <> 'terminated'";
        $bind = array_merge($names, [$serviceId]);

        if ($this->settings->uniqueScope() === 'server' && $serverId !== null) {
            $sql .= ' AND server_id = ?';
            $bind[] = $serverId;
        }

        foreach ($this->db->select($sql, $bind) as $row) {
            $taken[(string) $row['u']] = 'taken';
        }

        // 2. An open request elsewhere has reserved it.
        $open = UsernameChangeRepository::OPEN;
        $rows = $this->db->select(
            "SELECT new_username AS u FROM username_change_requests WHERE new_username IN ({$in}) AND service_id <> ? AND status IN (" . UsernameChangeRepository::placeholders($open) . ')',
            array_merge($names, [$serviceId], $open)
        );

        foreach ($rows as $row) {
            $taken[(string) $row['u']] ??= 'pending';
        }

        if ($serverId === null) {
            return $taken;
        }

        // 3. The server already has an account by that name (cron-refreshed copy).
        $rows = $this->db->select(
            "SELECT username AS u FROM username_change_server_accounts WHERE server_id = ? AND username IN ({$in})",
            array_merge([$serverId], $names)
        );

        foreach ($rows as $row) {
            $taken[(string) $row['u']] ??= 'taken';
        }

        // 4. MySQL (not MariaDB): the first 8 characters must be unique on the server.
        if ($this->first8Applies($serverId)) {
            $current = strtolower((string) ($service['username'] ?? ''));
            $prefixes = array_values(array_unique(array_map(static fn ($n) => substr($n, 0, 8), $names)));
            $rows = $this->db->select(
                'SELECT prefix8 FROM username_change_server_accounts WHERE server_id = ? AND username <> ? AND prefix8 IN (' . UsernameChangeRepository::placeholders($prefixes) . ')',
                array_merge([$serverId, $current], $prefixes)
            );
            $clash = array_flip(array_map(static fn ($r) => (string) $r['prefix8'], $rows));

            foreach ($names as $n) {
                if (isset($clash[substr($n, 0, 8)])) {
                    $taken[$n] ??= 'prefix8';
                }
            }
        }

        return $taken;
    }

    /**
     * Up to $limit free alternatives for $requested.
     *
     * @param array<string, mixed> $service
     * @param array<string, mixed> $context domain, first_name, last_name
     * @return array<int, string>
     */
    public function suggest(string $requested, array $service, array $context, int $limit = 5): array
    {
        $policy = $this->settings->policy();
        $candidates = $policy->suggestions($requested, $context + ['current' => (string) ($service['username'] ?? '')], 16);
        $taken = $this->takenAmong($candidates, $service);

        return array_slice(array_values(array_filter($candidates, static fn ($c) => !isset($taken[$c]))), 0, $limit);
    }

    public function first8Applies(int $serverId): bool
    {
        $rule = $this->settings->first8Rule();

        if ($rule !== 'auto') {
            return $rule === 'on';
        }

        $row = $this->db->selectOne('SELECT db_engine, db_engine_override FROM username_change_servers WHERE server_id = ?', [$serverId]);
        $engine = (string) (($row['db_engine_override'] ?? '') ?: ($row['db_engine'] ?? ''));

        return $engine === 'mysql';
    }

    /**
     * Replaces the local copy of a server's accounts with what WHM reports.
     * Returns the number of accounts, or null when the server did not answer
     * (in which case the old copy is kept — stale is better than empty).
     */
    public function refreshServer(int $serverId): ?int
    {
        if ($this->provisioning === null) {
            return null;
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $result = $this->provisioning->serverAccounts($serverId);

        if (!$result['success']) {
            $this->upsertServer($serverId, ['last_error' => mb_substr((string) $result['message'], 0, 250)]);

            return null;
        }

        $this->db->transaction(function () use ($serverId, $result, $now): void {
            $this->db->delete('DELETE FROM username_change_server_accounts WHERE server_id = ?', [$serverId]);

            foreach (array_chunk($result['accounts'], 200, true) as $chunk) {
                $values = [];
                $bind = [];

                foreach ($chunk as $user => $domain) {
                    $values[] = '(?, ?, ?, ?, ?)';
                    array_push($bind, $serverId, substr((string) $user, 0, 32), substr((string) $user, 0, 8), $domain === '' ? null : substr((string) $domain, 0, 191), $now);
                }

                $this->db->statement(
                    'INSERT IGNORE INTO username_change_server_accounts (server_id, username, prefix8, domain, synced_at) VALUES ' . implode(', ', $values),
                    $bind
                );
            }
        });

        $fields = ['account_count' => count($result['accounts']), 'accounts_synced_at' => $now, 'last_error' => null];
        $row = $this->db->selectOne('SELECT db_engine FROM username_change_servers WHERE server_id = ?', [$serverId]);

        if (($row['db_engine'] ?? null) === null) {
            $fields['db_engine'] = $this->provisioning->serverDatabaseEngine($serverId);
        }

        $this->upsertServer($serverId, $fields);

        return count($result['accounts']);
    }

    /** Keeps the local copy correct right after a rename, without waiting for cron. */
    public function recordRename(int $serverId, string $old, string $new): void
    {
        $this->db->delete('DELETE FROM username_change_server_accounts WHERE server_id = ? AND username = ?', [$serverId, strtolower($old)]);
        $this->db->statement(
            'INSERT IGNORE INTO username_change_server_accounts (server_id, username, prefix8, domain, synced_at) VALUES (?, ?, ?, NULL, ?)',
            [$serverId, strtolower($new), substr(strtolower($new), 0, 8), (new DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
    }

    /** The account name WHM holds for $domain on the server, per the local copy. */
    public function accountForDomain(int $serverId, string $domain): ?string
    {
        if ($domain === '') {
            return null;
        }

        $row = $this->db->selectOne(
            'SELECT username FROM username_change_server_accounts WHERE server_id = ? AND domain = ? LIMIT 1',
            [$serverId, strtolower($domain)]
        );

        return $row === null ? null : (string) $row['username'];
    }

    /** @return array<int, array<string, mixed>> */
    public function serverStates(): array
    {
        return $this->db->select(
            "SELECT sv.id, sv.name, sv.hostname, sv.active, u.db_engine, u.db_engine_override, u.account_count, u.accounts_synced_at, u.last_error
               FROM servers sv LEFT JOIN username_change_servers u ON u.server_id = sv.id
              WHERE sv.module_slug = 'cpanel' ORDER BY sv.name"
        );
    }

    public function setEngineOverride(int $serverId, ?string $engine): void
    {
        $this->upsertServer($serverId, ['db_engine_override' => in_array($engine, ['mysql', 'mariadb'], true) ? $engine : null]);
    }

    /** @return array<int, int> server ids whose copy is older than the cache window */
    public function staleServers(): array
    {
        $before = (new DateTimeImmutable('-' . self::ACCOUNT_CACHE_MINUTES . ' minutes'))->format('Y-m-d H:i:s');
        $rows = $this->db->select(
            "SELECT sv.id FROM servers sv LEFT JOIN username_change_servers u ON u.server_id = sv.id
              WHERE sv.module_slug = 'cpanel' AND sv.active = 1 AND (u.accounts_synced_at IS NULL OR u.accounts_synced_at < ?)",
            [$before]
        );

        return array_map(static fn ($r) => (int) $r['id'], $rows);
    }

    public static function takenMessage(string $code): string
    {
        return match ($code) {
            'pending' => 'That name is waiting on another request. Please choose another.',
            'prefix8' => 'The first 8 characters clash with another account on this server. Try changing the start of the name.',
            default => 'That username is already taken.',
        };
    }

    /** WHM's reason, in plain words, without leaking server details. */
    public static function friendlyServerReason(string $reason): string
    {
        $r = strtolower($reason);

        return match (true) {
            str_contains($r, 'already exists'), str_contains($r, 'in use'), str_contains($r, 'taken') => 'That username is already taken on the server.',
            str_contains($r, 'reserved') => 'That name is reserved by the server. Please choose another.',
            str_contains($r, 'database') || str_contains($r, 'prefix') => 'That name clashes with existing database names on the server. Try a different start.',
            str_contains($r, 'too long'), str_contains($r, 'length') => 'That name is too long for the server.',
            default => 'The server does not accept that username. Please choose another.',
        };
    }

    /** @param array<string, mixed> $fields */
    private function upsertServer(int $serverId, array $fields): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $exists = $this->db->selectOne('SELECT server_id FROM username_change_servers WHERE server_id = ?', [$serverId]);

        if ($exists === null) {
            $columns = array_merge(['server_id', 'updated_at'], array_keys($fields));
            $this->db->insert(
                'INSERT INTO username_change_servers (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
                array_merge([$serverId, $now], array_values($fields))
            );

            return;
        }

        $assign = implode(', ', array_map(static fn ($c) => "{$c} = ?", array_keys($fields)));
        $this->db->update(
            "UPDATE username_change_servers SET {$assign}, updated_at = ? WHERE server_id = ?",
            array_merge(array_values($fields), [$now, $serverId])
        );
    }
}
