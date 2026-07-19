<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;

/**
 * The one place a ticket's status transitions happen (blueprint §4.4
 * support ticketing engine) — a client reply reopens it for the admin
 * ("customer-reply"), a non-private admin reply marks it "answered" and
 * waiting on the client. Private notes never change status; they're
 * visible to staff only.
 */
final class TicketService
{
    public function __construct(
        private readonly TicketRepository $tickets,
        private readonly TicketReplyRepository $replies,
        private readonly HookDispatcher $hooks
    ) {
    }

    public function open(?int $clientId, string $email, int $departmentId, string $subject, string $authorName, string $message): int
    {
        $ticketId = $this->tickets->create([
            'client_id' => $clientId,
            'email' => $email,
            'department_id' => $departmentId,
            'subject' => $subject,
            'status' => 'open',
        ]);

        $this->replies->create($ticketId, 'client', $clientId, $authorName, $message);
        $this->hooks->fire(HookPoints::TICKET_OPEN, ['ticketId' => $ticketId]);

        return $ticketId;
    }

    public function reply(int $ticketId, string $authorType, ?int $authorId, string $authorName, string $message, bool $isPrivate = false): int
    {
        $replyId = $this->replies->create($ticketId, $authorType, $authorId, $authorName, $message, $isPrivate);

        if (!$isPrivate) {
            $newStatus = $authorType === 'admin' ? 'answered' : 'customer-reply';
            $this->tickets->recordReply($ticketId, $authorType, $newStatus);
            $this->hooks->fire(HookPoints::TICKET_REPLY, ['ticketId' => $ticketId, 'authorType' => $authorType]);
        }

        return $replyId;
    }

    public function close(int $ticketId): void
    {
        $this->tickets->setStatus($ticketId, 'closed');
        $this->hooks->fire(HookPoints::TICKET_CLOSE, ['ticketId' => $ticketId]);
    }

    public function reopen(int $ticketId): void
    {
        $this->tickets->setStatus($ticketId, 'open');
    }
}
