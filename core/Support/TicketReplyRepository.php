<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Database;
use DateTimeImmutable;

final class TicketReplyRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function forTicket(int $ticketId, bool $includePrivate): array
    {
        $where = $includePrivate ? '' : 'AND is_private = 0';

        return $this->db->select(
            "SELECT * FROM ticket_replies WHERE ticket_id = ? {$where} ORDER BY id",
            [$ticketId]
        );
    }

    public function create(int $ticketId, string $authorType, ?int $authorId, string $authorName, string $message, bool $isPrivate = false): int
    {
        return (int) $this->db->insert(
            'INSERT INTO ticket_replies (ticket_id, author_type, author_id, author_name, message, is_private, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$ticketId, $authorType, $authorId, $authorName, $message, $isPrivate ? 1 : 0, (new DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
    }

    /**
     * Re-points every reply on $fromTicketId onto $toTicketId (ticket
     * merge). `id` stays untouched, so the merged thread still displays in
     * true chronological order alongside the target's own replies.
     */
    public function moveToTicket(int $fromTicketId, int $toTicketId): int
    {
        return $this->db->update(
            'UPDATE ticket_replies SET ticket_id = ? WHERE ticket_id = ?',
            [$toTicketId, $fromTicketId]
        );
    }

    /**
     * Ticket split: moves the given reply AND every later reply on the
     * source ticket onto the new ticket (replies keep their ids, so the
     * split thread reads in the same order it always did). Replies older
     * than $fromReplyId stay on the source.
     */
    public function splitFrom(int $sourceTicketId, int $fromReplyId, int $newTicketId): int
    {
        return $this->db->update(
            'UPDATE ticket_replies SET ticket_id = ? WHERE ticket_id = ? AND id >= ?',
            [$newTicketId, $sourceTicketId, $fromReplyId]
        );
    }
}
