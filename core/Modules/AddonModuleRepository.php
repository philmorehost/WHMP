<?php

declare(strict_types=1);

namespace CodeVault\Modules;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Persists which registered AddonModule slugs an admin has actually turned
 * on, plus each addon's saved config — the activation half of the addon
 * lifecycle that ModuleManager's in-memory registry deliberately doesn't
 * own (registration happens unconditionally at boot for every known addon;
 * activation is an admin action that should survive a restart).
 */
final class AddonModuleRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    public function isActive(string $slug): bool
    {
        $row = $this->db->selectOne('SELECT enabled FROM addon_modules WHERE slug = ?', [$slug]);

        return $row !== null && (int) $row['enabled'] === 1;
    }

    /** @return array<string, mixed>|null */
    public function find(string $slug): ?array
    {
        return $this->db->selectOne('SELECT * FROM addon_modules WHERE slug = ?', [$slug]);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM addon_modules ORDER BY slug ASC');
    }

    public function activate(string $slug): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($this->find($slug) === null) {
            $this->db->insert(
                'INSERT INTO addon_modules (slug, enabled, activated_at, created_at, updated_at) VALUES (?, 1, ?, ?, ?)',
                [$slug, $now, $now, $now]
            );

            return;
        }

        $this->db->update(
            'UPDATE addon_modules SET enabled = 1, activated_at = ?, updated_at = ? WHERE slug = ?',
            [$now, $now, $slug]
        );
    }

    public function deactivate(string $slug): void
    {
        $this->db->update(
            'UPDATE addon_modules SET enabled = 0, updated_at = ? WHERE slug = ?',
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
                'INSERT INTO addon_modules (slug, enabled, config, created_at, updated_at) VALUES (?, 0, ?, ?, ?)',
                [$slug, $encoded, $now, $now]
            );

            return;
        }

        $this->db->update(
            'UPDATE addon_modules SET config = ?, updated_at = ? WHERE slug = ?',
            [$encoded, $now, $slug]
        );
    }
}
