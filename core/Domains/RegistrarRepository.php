<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Database;
use DateTimeImmutable;

final class RegistrarRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM registrars ORDER BY sort_order, name');
    }

    /** @return array<int, array<string, mixed>> */
    public function allEnabled(): array
    {
        return $this->db->select('SELECT * FROM registrars WHERE is_enabled = 1 ORDER BY sort_order, name');
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->selectOne('SELECT * FROM registrars WHERE slug = ?', [$slug]);
    }

    /** @return array<string, mixed> decoded config, or [] if none set */
    public function configFor(string $slug): array
    {
        $registrar = $this->findBySlug($slug);
        $config = $registrar['config'] ?? null;
        $decoded = $config !== null ? json_decode((string) $config, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    public function setEnabled(string $slug, bool $enabled): void
    {
        $this->db->update(
            'UPDATE registrars SET is_enabled = ?, updated_at = ? WHERE slug = ?',
            [$enabled ? 1 : 0, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $slug]
        );
    }

    /** @param array<string, mixed> $config */
    public function setConfig(string $slug, array $config): void
    {
        $this->db->update(
            'UPDATE registrars SET config = ?, updated_at = ? WHERE slug = ?',
            [json_encode($config), (new DateTimeImmutable())->format('Y-m-d H:i:s'), $slug]
        );
    }
}
