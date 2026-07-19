<?php

declare(strict_types=1);

namespace CodeVault\Notifications;

use CodeVault\Database;
use DateTimeImmutable;

final class NotificationEndpointRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM notification_endpoints ORDER BY id DESC');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM notification_endpoints WHERE id = ?', [$id]);
    }

    /**
     * @return array<int, array<string, mixed>> active endpoints subscribed
     *   to the given hook point — filtered in PHP rather than SQL LIKE
     *   matching against the JSON events column, since the row count here
     *   is small (admin-configured, not user data at scale).
     */
    public function forHookPoint(string $hookPoint): array
    {
        $active = $this->db->select('SELECT * FROM notification_endpoints WHERE is_active = 1');

        return array_values(array_filter($active, static function (array $endpoint) use ($hookPoint) {
            $events = json_decode((string) $endpoint['events'], true) ?: [];

            return in_array($hookPoint, $events, true);
        }));
    }

    /** @param array<int, string> $events */
    public function create(string $type, string $name, string $url, ?string $secret, array $events): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO notification_endpoints (type, name, url, secret, events, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)',
            [$type, $name, $url, $secret, json_encode($events), $now, $now]
        );
    }

    public function setActive(int $id, bool $active): void
    {
        $this->db->update('UPDATE notification_endpoints SET is_active = ?, updated_at = ? WHERE id = ?', [$active ? 1 : 0, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]);
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM notification_endpoints WHERE id = ?', [$id]);
    }
}
