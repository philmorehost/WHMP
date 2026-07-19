<?php

declare(strict_types=1);

namespace CodeVault\Settings;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * General key/value settings store (blueprint §4.3) — populated
 * incrementally as each feature needs a setting, rather than an upfront
 * schema for keys nothing reads yet.
 */
final class SettingsRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $row = $this->db->selectOne('SELECT `value` FROM settings WHERE `key` = ?', [$key]);

        return $row['value'] ?? $default;
    }

    public function set(string $key, string $value): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->insert(
            'INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)',
            [$key, $value, $now]
        );
    }
}
