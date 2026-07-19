<?php

declare(strict_types=1);

namespace CodeVault\Activity;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * General audit/activity log (blueprint §4.5). Called directly from
 * controllers at the point of action — simpler and more explicit than
 * threading every mutation through the hook system just to log it.
 */
final class ActivityLogger
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * @param 'admin'|'client'|'system' $actorType
     */
    public function log(
        string $actorType,
        ?int $actorId,
        string $action,
        ?string $subjectType,
        ?int $subjectId,
        string $description,
        ?string $ipAddress = null
    ): void {
        $this->db->insert(
            'INSERT INTO activity_log (actor_type, actor_id, action, subject_type, subject_id, description, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$actorType, $actorId, $action, $subjectType, $subjectId, $description, $ipAddress, (new DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 50): array
    {
        return $this->db->select('SELECT * FROM activity_log ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
    }

    /** @return array<int, array<string, mixed>> */
    public function forSubject(string $subjectType, int $subjectId, int $limit = 50): array
    {
        return $this->db->select(
            'SELECT * FROM activity_log WHERE subject_type = ? AND subject_id = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)),
            [$subjectType, $subjectId]
        );
    }

    /** Used by DataPruningJob (blueprint §4.4 "pruning automation") — deletes rows older than the given timestamp, returns how many were removed. */
    public function deleteOlderThan(string $beforeDateTime): int
    {
        return $this->db->delete('DELETE FROM activity_log WHERE created_at < ?', [$beforeDateTime]);
    }
}
