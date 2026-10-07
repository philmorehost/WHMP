<?php

declare(strict_types=1);

namespace CodeVault\UsernameChanger;

use CodeVault\Database;
use CodeVault\Provisioning\ProvisioningService;
use DateTimeImmutable;

/**
 * Is a username free?
 *
 *  - fast()  — local rules plus indexed lookups: services.username, open
 *              requests, and the local copy of every WHM account on the server.
 *              No network. Settles invalid and known-taken names in about a
 *              millisecond of database time.
 *  - live()  — fast() and then, only for a name that passed, WHM's own
 *              `verify_new_username` over a short-timeout client. The local copy
 *              can be stale or empty (cron not running, a fresh install, an
 *              account created in WHM a minute ago), so "free locally" is never
 *              reported as "available" without asking the server. This backs the
 *              modal's live check, the admin manual check, submit, and the final
 *              check before the rename. A server that cannot be reached is
 *              `unverified` — never "available".
 *  - deep()  — the same as live(); kept for existing callers.
 *
 * A name WHM rejects as an existing account is added to the local copy at once,
 * so the next check of it (by anyone) is settled locally, and ensureFresh()
 * re-syncs a stale copy when the modal opens instead of waiting for cron.
 *
 * Nothing here ever returns WHO holds a name — only whether it is free.
 */
final class UsernameAvailability
{
    /** How old the server account copy may get before cron refreshes it. */
    public const ACCOUNT_CACHE_MINUTES = 15;

    /** How long one on-demand refresh attempt holds off the next for a server. */
    public const REFRESH_RETRY_SECONDS = 120;

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
     * @param array<string, mixed> $service id, server_id, username
     * @return array{ok: bool, code: string, message: string}
     */
    public function live(string $name, array $service): array
    {
        $fast = $this->fast($name, $service);

        if (!$fast['ok'] || $this->provisioning === null || ($service['server_id'] ?? null) === null) {
            return $fast;
        }

        $name = UsernamePolicy::normalise($name);
        $whm = $this->provisioning->verifyNewUsername((int) $service['id'], $name);

        if (!$whm['reachable']) {
            return ['ok' => false, 'code' => 'unverified', 'message' => 'We couldn\'t reach the hosting server to confirm this name. Please try again in a moment.'];
        }

        if (!$whm['available']) {
            $message = self::friendlyServerReason((string) $whm['message']);

            if (self::meansExistingAccount((string) $whm['message'])) {
                $this->rememberTaken((int) $service['server_id'], $name);
            }

            return ['ok' => false, 'code' => 'server', 'message' => $message];
        }

        return $fast;
    }

    /**
     * @param array<string, mixed> $service
     * @return array{ok: bool, code: string, message: string}
     */
    public function deep(string $name, array $service): array
    {
        return $this->live($name, $service);
    }

    /**
     * Re-syncs the server's account copy when it is older than the cache window
     * (or was never synced), so the live check does not depend on cron having
     * run. At most one attempt per server every REFRESH_RETRY_SECONDS, claimed
     * with a single conditional UPDATE so a burst of modal opens triggers one
     * listaccts, not one each. Returns true when a refresh ran and succeeded.
     */
    public function ensureFresh(int $serverId): bool
    {
        if ($this->provisioning === null || $serverId <= 0) {
            return false;
        }

        $row = $this->db->selectOne('SELECT accounts_synced_at FROM username_change_servers WHERE server_id = ?', [$serverId]);
        $staleBefore = (new DateTimeImmutable('-' . self::ACCOUNT_CACHE_MINUTES . ' minutes'))->format('Y-m-d H:i:s');
        $synced = $row['accounts_synced_at'] ?? null;

        if ($synced !== null && (string) $synced >= $staleBefore) {
            return false;
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($row === null) {
            // A placeholder old enough to be claimed straight away.
            $this->db->insert(
                'INSERT IGNORE INTO username_change_servers (server_id, account_count, updated_at) VALUES (?, 0, ?)',
                [$serverId, '2000-01-01 00:00:00']
            );
        }

        $claimed = $this->db->update(
            'UPDATE username_change_servers SET updated_at = ? WHERE server_id = ? AND updated_at < ? AND (accounts_synced_at IS NULL OR accounts_synced_at < ?)',
            [$now, $serverId, (new DateTimeImmutable('-' . self::REFRESH_RETRY_SECONDS . ' seconds'))->format('Y-m-d H:i:s'), $staleBefore]
        );

        if ($claimed !== 1) {
            return false;
        }

        return $this->refreshServer($serverId) !== null;
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

    /** Whether a WHM rejection reason says an account by that name already exists. */
    public static function meansExistingAccount(string $reason): bool
    {
        $r = strtolower($reason);

        return str_contains($r, 'already exists') || str_contains($r, 'in use') || str_contains($r, 'taken');
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

    /**
     * Adds a name WHM just reported as an existing account to the local copy,
     * so the next check of it is settled without a network call. The next full
     * refresh replaces the copy wholesale, so a wrong entry cannot linger.
     */
    private function rememberTaken(int $serverId, string $name): void
    {
        try {
            $this->db->insert(
                'INSERT IGNORE INTO username_change_server_accounts (server_id, username, prefix8, domain, synced_at) VALUES (?, ?, ?, NULL, ?)',
                [$serverId, substr($name, 0, 32), substr($name, 0, 8), (new DateTimeImmutable())->format('Y-m-d H:i:s')]
            );
        } catch (\Throwable) {
            // A cache write must never turn a correct answer into an error.
        }
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
