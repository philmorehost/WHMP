<?php

declare(strict_types=1);

namespace CodeVault\Localization;

use CodeVault\Database;
use DateTimeImmutable;

final class LanguageRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM languages ORDER BY is_default DESC, name ASC');
    }

    /** @return array<int, array<string, mixed>> */
    public function active(): array
    {
        return $this->db->select('SELECT * FROM languages WHERE is_active = 1 ORDER BY is_default DESC, name ASC');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM languages WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        return $this->db->selectOne('SELECT * FROM languages WHERE code = ?', [$code]);
    }

    /** @return array<string, mixed> */
    public function default(): array
    {
        $row = $this->db->selectOne('SELECT * FROM languages WHERE is_default = 1 LIMIT 1');

        // Migration 0070 seeds exactly one default ('en') and setDefault()
        // never leaves zero defaults — unreachable outside a corrupted
        // database, so fail loudly rather than silently picking a language.
        if ($row === null) {
            throw new \RuntimeException('No default language configured.');
        }

        return $row;
    }

    public function setActive(int $id, bool $active): void
    {
        $this->db->update('UPDATE languages SET is_active = ?, updated_at = ? WHERE id = ?', [$active ? 1 : 0, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]);
    }

    public function setDefault(int $id): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->transaction(function () use ($id, $now) {
            $this->db->update('UPDATE languages SET is_default = 0, updated_at = ?', [$now]);
            $this->db->update('UPDATE languages SET is_default = 1, is_active = 1, updated_at = ? WHERE id = ?', [$now, $id]);

            return null;
        });
    }
}
