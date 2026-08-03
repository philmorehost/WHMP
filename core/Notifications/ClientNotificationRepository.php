<?php

declare(strict_types=1);

namespace CodeVault\Notifications;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * In-app client notification center. One `notifications` row per send —
 * whether that's an admin broadcast to one client, a hand-picked set, or
 * everyone — with a `notification_recipients` child row per client tracking
 * read state individually. Mirrors the mail_campaigns/mail_campaign_recipients
 * split so a 1,000-client broadcast is still one row here, not 1,000.
 */
final class ClientNotificationRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * @param array<int, int> $clientIds
     * @return int the new notification's id
     */
    public function send(string $subject, string $body, array $clientIds, string $source = 'admin', ?int $emailLogId = null, ?int $createdByAdminId = null): int
    {
        $clientIds = array_values(array_unique(array_filter(array_map('intval', $clientIds), static fn (int $id): bool => $id > 0)));

        if ($clientIds === []) {
            return 0;
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $notificationId = (int) $this->db->insert(
            'INSERT INTO notifications (subject, body, source, email_log_id, created_by_admin_id, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$subject, $body, $source, $emailLogId, $createdByAdminId, $now]
        );

        foreach ($clientIds as $clientId) {
            $this->db->insert(
                'INSERT INTO notification_recipients (notification_id, client_id, created_at) VALUES (?, ?, ?)',
                [$notificationId, $clientId, $now]
            );
        }

        return $notificationId;
    }

    /** Convenience for the single-client case (individual admin send, and every mirrored system email). */
    public function sendToOne(string $subject, string $body, int $clientId, string $source = 'admin', ?int $emailLogId = null, ?int $createdByAdminId = null): int
    {
        return $this->send($subject, $body, [$clientId], $source, $emailLogId, $createdByAdminId);
    }

    /**
     * Every sent notification, newest first, with recipient/read counts —
     * the admin-facing history list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allForAdmin(int $limit = 50): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT n.*, a.display_name AS created_by_name,
                COUNT(r.id) AS recipient_count,
                SUM(r.read_at IS NOT NULL) AS read_count
            FROM notifications n
            LEFT JOIN notification_recipients r ON r.notification_id = n.id
            LEFT JOIN admins a ON a.id = n.created_by_admin_id
            GROUP BY n.id
            ORDER BY n.id DESC
            LIMIT
            SQL . ' ' . max(1, min(200, $limit))
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM notifications WHERE id = ?', [$id]);
    }

    /** Recipients of one sent notification, for the admin detail view. @return array<int, array<string, mixed>> */
    public function recipientsFor(int $notificationId): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT r.*, c.email, c.first_name, c.last_name
            FROM notification_recipients r
            JOIN clients c ON c.id = r.client_id
            WHERE r.notification_id = ?
            ORDER BY r.id ASC
            SQL,
            [$notificationId]
        );
    }

    /**
     * A client's own notification feed, newest first — the client-facing list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forClient(int $clientId, int $limit = 50): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT n.*, r.id AS recipient_id, r.read_at, r.reply_ticket_id
            FROM notification_recipients r
            JOIN notifications n ON n.id = r.notification_id
            WHERE r.client_id = ?
            ORDER BY n.id DESC
            LIMIT
            SQL . ' ' . max(1, min(200, $limit)),
            [$clientId]
        );
    }

    /** Ownership-checked single notification for the client detail/reply page. @return array<string, mixed>|null */
    public function findForClient(int $notificationId, int $clientId): ?array
    {
        return $this->db->selectOne(
            <<<'SQL'
            SELECT n.*, r.id AS recipient_id, r.read_at, r.reply_ticket_id
            FROM notification_recipients r
            JOIN notifications n ON n.id = r.notification_id
            WHERE n.id = ? AND r.client_id = ?
            SQL,
            [$notificationId, $clientId]
        );
    }

    public function markRead(int $recipientId): void
    {
        $this->db->update(
            'UPDATE notification_recipients SET read_at = ? WHERE id = ? AND read_at IS NULL',
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $recipientId]
        );
    }

    public function recordReplyTicket(int $recipientId, int $ticketId): void
    {
        $this->db->update(
            'UPDATE notification_recipients SET reply_ticket_id = ? WHERE id = ?',
            [$ticketId, $recipientId]
        );
    }

    public function unreadCount(int $clientId): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM notification_recipients WHERE client_id = ? AND read_at IS NULL',
            [$clientId]
        );

        return (int) ($row['c'] ?? 0);
    }
}
