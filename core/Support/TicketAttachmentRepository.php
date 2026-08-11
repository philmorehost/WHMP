<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Database;
use DateTimeImmutable;

final class TicketAttachmentRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    public function create(int $ticketId, ?int $replyId, string $uploadedBy, string $originalName, string $storedName, string $mimeType, int $sizeBytes): int
    {
        return (int) $this->db->insert(
            'INSERT INTO ticket_attachments (ticket_id, reply_id, uploaded_by, original_name, stored_name, mime_type, size_bytes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$ticketId, $replyId, $uploadedBy, $originalName, $storedName, $mimeType, $sizeBytes, (new DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
    }

    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM ticket_attachments WHERE id = ?', [$id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function forTicket(int $ticketId): array
    {
        return $this->db->select('SELECT * FROM ticket_attachments WHERE ticket_id = ? ORDER BY id ASC', [$ticketId]);
    }

    /**
     * Attachments grouped by the reply they belong to (key 0 = attachments on
     * the original ticket, not a specific reply), so the thread view can show
     * each message's files beneath it.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function forTicketGroupedByReply(int $ticketId): array
    {
        $grouped = [];

        foreach ($this->forTicket($ticketId) as $row) {
            $grouped[(int) ($row['reply_id'] ?? 0)][] = $row;
        }

        return $grouped;
    }

    /**
     * Re-points every attachment on $fromTicketId onto $toTicketId (ticket
     * merge) — their reply_id already moved with the reply itself
     * (TicketReplyRepository::moveToTicket()), but ticket_id is its own
     * column here and is what controller ownership checks compare against.
     */
    public function moveToTicket(int $fromTicketId, int $toTicketId): int
    {
        return $this->db->update(
            'UPDATE ticket_attachments SET ticket_id = ? WHERE ticket_id = ?',
            [$toTicketId, $fromTicketId]
        );
    }

    /**
     * Ticket split: moves only the attachments attached to replies at or
     * after the split point (those replies already moved to the new ticket
     * with TicketReplyRepository::splitFrom()). Attachments on replies that
     * stayed behind keep the original ticket id.
     */
    public function moveSplitToTicket(int $fromTicketId, int $fromReplyId, int $toTicketId): int
    {
        return $this->db->update(
            'UPDATE ticket_attachments SET ticket_id = ? WHERE ticket_id = ? AND reply_id IS NOT NULL AND reply_id >= ?',
            [$toTicketId, $fromTicketId, $fromReplyId]
        );
    }
}
