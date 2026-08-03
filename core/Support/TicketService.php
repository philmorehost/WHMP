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
        private readonly HookDispatcher $hooks,
        private readonly TicketAttachmentRepository $attachments
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

    /**
     * A ticket's identity for merge purposes: its client account when it has
     * one, otherwise the reporting email address (a piped-in ticket with no
     * matching client still needs *some* identity to compare against).
     */
    public function isCrossClientMerge(array $source, array $target): bool
    {
        $sourceIdentity = $source['client_id'] !== null
            ? 'c:' . $source['client_id']
            : 'e:' . strtolower((string) $source['email']);
        $targetIdentity = $target['client_id'] !== null
            ? 'c:' . $target['client_id']
            : 'e:' . strtolower((string) $target['email']);

        return $sourceIdentity !== $targetIdentity;
    }

    /**
     * Merges $sourceId into $targetId: every reply and attachment moves onto
     * the target (a private note marks where they came from), and the
     * source is closed and left pointing at its merge target — rather than
     * deleted — so a stale link to the old ticket number still lands
     * somewhere coherent (TicketController::show() redirects it).
     *
     * Merging tickets that belong to different clients is allowed (support
     * staff sometimes genuinely need to, e.g. a shared account issue raised
     * by two contacts) but is unusual enough to be a likely mis-click, so
     * the caller is expected to have already confirmed it with the admin —
     * this method just reports whether it happened (crossClient) so the
     * caller can log/flag it either way.
     *
     * @return array{success: bool, error: ?string, crossClient: bool}
     */
    public function merge(int $sourceId, int $targetId, int $mergedByAdminId, string $mergedByAdminName): array
    {
        if ($sourceId === $targetId) {
            return ['success' => false, 'error' => 'A ticket cannot be merged into itself.', 'crossClient' => false];
        }

        $source = $this->tickets->find($sourceId);
        $target = $this->tickets->find($targetId);

        if ($source === null || $target === null) {
            return ['success' => false, 'error' => 'Ticket not found.', 'crossClient' => false];
        }

        if ($source['merged_into_id'] !== null) {
            return ['success' => false, 'error' => "Ticket #{$sourceId} was already merged into #{$source['merged_into_id']}.", 'crossClient' => false];
        }

        if ($target['merged_into_id'] !== null) {
            return ['success' => false, 'error' => "Ticket #{$targetId} is itself merged into another ticket — merge into #{$target['merged_into_id']} instead.", 'crossClient' => false];
        }

        $crossClient = $this->isCrossClientMerge($source, $target);

        $this->replies->create(
            $targetId,
            'admin',
            $mergedByAdminId,
            $mergedByAdminName,
            "— Merged ticket #{$sourceId} (\"{$source['subject']}\") into this ticket —",
            true
        );
        $this->replies->moveToTicket($sourceId, $targetId);
        $this->attachments->moveToTicket($sourceId, $targetId);
        $this->tickets->setMergedInto($sourceId, $targetId);

        $this->hooks->fire(HookPoints::TICKET_MERGED, [
            'sourceTicketId' => $sourceId,
            'targetTicketId' => $targetId,
            'crossClient' => $crossClient,
        ]);

        return ['success' => true, 'error' => null, 'crossClient' => $crossClient];
    }
}
