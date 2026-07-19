<?php

declare(strict_types=1);

namespace CodeVault\Modules;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Persists which registered SecurityQuestionModule slugs an admin has
 * turned on for clients to choose from. Mirrors ReportModuleRepository
 * (R27) exactly — same activation-state shape, same reasoning for a
 * dedicated table over a shared one.
 */
final class SecurityQuestionModuleRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    public function isActive(string $slug): bool
    {
        $row = $this->db->selectOne('SELECT enabled FROM security_question_modules WHERE slug = ?', [$slug]);

        return $row !== null && (int) $row['enabled'] === 1;
    }

    /** @return array<string, mixed>|null */
    public function find(string $slug): ?array
    {
        return $this->db->selectOne('SELECT * FROM security_question_modules WHERE slug = ?', [$slug]);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM security_question_modules ORDER BY slug ASC');
    }

    public function activate(string $slug): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($this->find($slug) === null) {
            $this->db->insert(
                'INSERT INTO security_question_modules (slug, enabled, activated_at, created_at, updated_at) VALUES (?, 1, ?, ?, ?)',
                [$slug, $now, $now, $now]
            );

            return;
        }

        $this->db->update(
            'UPDATE security_question_modules SET enabled = 1, activated_at = ?, updated_at = ? WHERE slug = ?',
            [$now, $now, $slug]
        );
    }

    public function deactivate(string $slug): void
    {
        $this->db->update(
            'UPDATE security_question_modules SET enabled = 0, updated_at = ? WHERE slug = ?',
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $slug]
        );
    }
}
