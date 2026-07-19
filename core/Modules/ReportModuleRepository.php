<?php

declare(strict_types=1);

namespace CodeVault\Modules;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Persists which registered ReportModule slugs an admin has turned on
 * under Admin → Reports, plus each report's saved config. Mirrors
 * WidgetModuleRepository (R21) exactly — same activation-state shape, same
 * reasoning for a dedicated table over a shared one (see that class'
 * docblock and the report_modules migration).
 */
final class ReportModuleRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    public function isActive(string $slug): bool
    {
        $row = $this->db->selectOne('SELECT enabled FROM report_modules WHERE slug = ?', [$slug]);

        return $row !== null && (int) $row['enabled'] === 1;
    }

    /** @return array<string, mixed>|null */
    public function find(string $slug): ?array
    {
        return $this->db->selectOne('SELECT * FROM report_modules WHERE slug = ?', [$slug]);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM report_modules ORDER BY slug ASC');
    }

    public function activate(string $slug): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($this->find($slug) === null) {
            $this->db->insert(
                'INSERT INTO report_modules (slug, enabled, activated_at, created_at, updated_at) VALUES (?, 1, ?, ?, ?)',
                [$slug, $now, $now, $now]
            );

            return;
        }

        $this->db->update(
            'UPDATE report_modules SET enabled = 1, activated_at = ?, updated_at = ? WHERE slug = ?',
            [$now, $now, $slug]
        );
    }

    public function deactivate(string $slug): void
    {
        $this->db->update(
            'UPDATE report_modules SET enabled = 0, updated_at = ? WHERE slug = ?',
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $slug]
        );
    }
}
