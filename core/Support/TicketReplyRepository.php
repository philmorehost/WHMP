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
}
