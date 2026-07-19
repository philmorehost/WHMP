<?php

declare(strict_types=1);

namespace CodeVault\Localization;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Admin-editable per-string overrides layered on top of the file-based
 * catalogs (resources/lang/{code}.php) — lets staff correct or customize
 * one string without touching a file catalog under version control.
 */
final class TranslationOverrideRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<string, string> key => value, for fast lookup while translating */
    public function mapForLanguage(int $languageId): array
    {
        $rows = $this->db->select('SELECT `key`, `value` FROM translation_overrides WHERE language_id = ?', [$languageId]);
        $map = [];

        foreach ($rows as $row) {
            $map[$row['key']] = $row['value'];
        }

        return $map;
    }

    /** @return array<int, array<string, mixed>> full rows, for the admin editor */
    public function forLanguage(int $languageId): array
    {
        return $this->db->select('SELECT * FROM translation_overrides WHERE language_id = ? ORDER BY `key` ASC', [$languageId]);
    }

    public function set(int $languageId, string $key, string $value): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->insert(
            'INSERT INTO translation_overrides (language_id, `key`, `value`, created_at, updated_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)',
            [$languageId, $key, $value, $now, $now]
        );
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM translation_overrides WHERE id = ?', [$id]);
    }
}
