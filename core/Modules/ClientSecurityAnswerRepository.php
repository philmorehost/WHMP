<?php

declare(strict_types=1);

namespace CodeVault\Modules;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Persists a client's chosen SecurityQuestionModule slug and hashed
 * answer. One row per client (`client_id` is the primary key) — a client
 * has at most one configured security question at a time, matching how
 * WHMCS's own single security-question field works; setting a new one
 * overwrites the old.
 */
final class ClientSecurityAnswerRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<string, mixed>|null */
    public function find(int $clientId): ?array
    {
        return $this->db->selectOne('SELECT * FROM client_security_answers WHERE client_id = ?', [$clientId]);
    }

    public function set(int $clientId, string $moduleSlug, string $answerHash): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($this->find($clientId) === null) {
            $this->db->insert(
                'INSERT INTO client_security_answers (client_id, module_slug, answer_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                [$clientId, $moduleSlug, $answerHash, $now, $now]
            );

            return;
        }

        $this->db->update(
            'UPDATE client_security_answers SET module_slug = ?, answer_hash = ?, updated_at = ? WHERE client_id = ?',
            [$moduleSlug, $answerHash, $now, $clientId]
        );
    }

    public function clear(int $clientId): void
    {
        $this->db->delete('DELETE FROM client_security_answers WHERE client_id = ?', [$clientId]);
    }
}
