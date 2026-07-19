<?php

declare(strict_types=1);

namespace CodeVault\Modules;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Persists which registered WidgetModule slugs an admin has turned on for
 * the dashboard, plus each widget's saved config. Mirrors
 * AddonModuleRepository (R20) exactly — same activation-state shape, same
 * reasoning for a dedicated table over a shared one (see that class'
 * docblock and the widget_modules migration).
 */
final class WidgetModuleRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    public function isActive(string $slug): bool
    {
        $row = $this->db->selectOne('SELECT enabled FROM widget_modules WHERE slug = ?', [$slug]);

        return $row !== null && (int) $row['enabled'] === 1;
    }

    /** @return array<string, mixed>|null */
    public function find(string $slug): ?array
    {
        return $this->db->selectOne('SELECT * FROM widget_modules WHERE slug = ?', [$slug]);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM widget_modules ORDER BY slug ASC');
    }

    public function activate(string $slug): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($this->find($slug) === null) {
            $this->db->insert(
                'INSERT INTO widget_modules (slug, enabled, activated_at, created_at, updated_at) VALUES (?, 1, ?, ?, ?)',
                [$slug, $now, $now, $now]
            );

            return;
        }

        $this->db->update(
            'UPDATE widget_modules SET enabled = 1, activated_at = ?, updated_at = ? WHERE slug = ?',
            [$now, $now, $slug]
        );
    }

    public function deactivate(string $slug): void
    {
        $this->db->update(
            'UPDATE widget_modules SET enabled = 0, updated_at = ? WHERE slug = ?',
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $slug]
        );
    }

    /** @return array<string, mixed> */
    public function getConfig(string $slug): array
    {
        $row = $this->find($slug);
        if ($row === null || $row['config'] === null) {
            return [];
        }

        $decoded = json_decode((string) $row['config'], true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $config */
    public function setConfig(string $slug, array $config): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $encoded = json_encode($config);

        if ($this->find($slug) === null) {
            $this->db->insert(
                'INSERT INTO widget_modules (slug, enabled, config, created_at, updated_at) VALUES (?, 0, ?, ?, ?)',
                [$slug, $encoded, $now, $now]
            );

            return;
        }

        $this->db->update(
            'UPDATE widget_modules SET config = ?, updated_at = ? WHERE slug = ?',
            [$encoded, $now, $slug]
        );
    }
}
