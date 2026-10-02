<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Support\TicketReplyRepository;
use CodeVault\Support\TicketRepository;
use CodeVault\Support\TicketService;

/**
 * A store's side of support: its own customers' tickets, and passing one up to us.
 *
 * WHY A STORE'S ANSWERS GO THROUGH TicketService::reply()
 *
 * Because that is the one place a ticket's status moves — it stamps
 * `last_reply_at`, sets "answered", and fires the reply hooks exactly as a reply
 * from the platform's ticket page does. Writing to the replies table directly
 * would produce a store's reply that looks delivered but leaves the ticket sitting
 * in "open", which is how a customer's ticket quietly stops being anyone's job.
 *
 * WHY EVERY METHOD TAKES A CLIENT ID AND NOT A STORE ID
 *
 * Because the portal is a client area: the reseller IS a client of ours, and the
 * client id comes from the authenticated session. Taking a store id would mean
 * trusting one from the request, which is the shape of bug that lets one store act
 * on another's tickets. Resolving the store from the session's client id makes that
 * unrepresentable, and every ticket lookup is then scoped to that store INSIDE the
 * query (see TicketRepository::forResellerTicket).
 *
 * REPLIES ARE AUTHORED AS 'reseller', NOT 'admin'
 *
 * Functionally a store's reply is support answering its customer, which is why
 * TicketService::isSupportAuthor() counts it as support for the status transition.
 * But it must stay distinguishable from OUR reply in the record: an escalation is a
 * store asking us for help, and only our answer closes it. Recording a store's reply
 * as 'admin' would make the two indistinguishable.
 */
final class ResellerTicketService
{
    public function __construct(
        private readonly ResellerStoreRepository $stores,
        private readonly TicketRepository $tickets,
        private readonly TicketReplyRepository $replies,
        private readonly TicketService $ticketService
    ) {
    }

    /** The store this client owns, or null when they have not opened one. */
    public function storeForClient(int $clientId): ?array
    {
        return $this->stores->forClient($clientId);
    }

    /**
     * This store's tickets, or an empty list when it has no store.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ticketsForClient(int $clientId): array
    {
        $store = $this->stores->forClient($clientId);

        return $store === null ? [] : $this->tickets->forReseller((int) $store['id']);
    }

    /**
     * One of this store's tickets, or null when it is not theirs.
     *
     * @return array<string, mixed>|null
     */
    public function ticketForClient(int $clientId, int $ticketId): ?array
    {
        $store = $this->stores->forClient($clientId);

        return $store === null ? null : $this->tickets->forResellerTicket($ticketId, (int) $store['id']);
    }

    /**
     * A ticket's conversation.
     *
     * Private notes are deliberately excluded: the platform's own staff mark notes
     * private for each other, and a store reading them would see internal
     * commentary about its own customer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function repliesForClient(int $clientId, int $ticketId): array
    {
        if ($this->ticketForClient($clientId, $ticketId) === null) {
            return [];
        }

        return $this->replies->forTicket($ticketId, includePrivate: false);
    }

    /**
     * The store answers its customer.
     *
     * @return array{success: bool, error: ?string}
     */
    public function reply(int $clientId, int $ticketId, string $message, string $authorName): array
    {
        $ticket = $this->ticketForClient($clientId, $ticketId);

        if ($ticket === null) {
            return ['success' => false, 'error' => 'That ticket does not belong to your store.'];
        }

        if (trim($message) === '') {
            return ['success' => false, 'error' => 'A reply cannot be empty.'];
        }

        if ((string) $ticket['status'] === 'closed') {
            return ['success' => false, 'error' => 'That ticket is closed — reopen it before replying.'];
        }

        $this->ticketService->reply($ticketId, 'reseller', $clientId, $authorName, $message);

        return ['success' => true, 'error' => null];
    }

    /**
     * Pass a ticket up to the platform, with a note saying what was already tried.
     *
     * The note is required. "I cannot fix this" without saying what was attempted
     * makes the platform start from the beginning, which is the delay the escalation
     * was meant to avoid.
     *
     * @return array{success: bool, error: ?string}
     */
    public function escalate(int $clientId, int $ticketId, string $note): array
    {
        $store = $this->stores->forClient($clientId);
        $ticket = $this->ticketForClient($clientId, $ticketId);

        if ($store === null || $ticket === null) {
            return ['success' => false, 'error' => 'That ticket does not belong to your store.'];
        }

        if (trim($note) === '') {
            return ['success' => false, 'error' => 'Say what you have already tried — support starts from there.'];
        }

        if (!$this->tickets->escalateForReseller($ticketId, (int) $store['id'], trim($note))) {
            return ['success' => false, 'error' => 'That ticket has already been passed to support.'];
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * Take the request back — the store resolved it itself after all.
     *
     * @return array{success: bool, error: ?string}
     */
    public function withdraw(int $clientId, int $ticketId): array
    {
        $store = $this->stores->forClient($clientId);

        if ($store === null || $this->ticketForClient($clientId, $ticketId) === null) {
            return ['success' => false, 'error' => 'That ticket does not belong to your store.'];
        }

        if (!$this->tickets->withdrawEscalationForReseller($ticketId, (int) $store['id'])) {
            return ['success' => false, 'error' => 'That ticket is not waiting on support.'];
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * Everything stores have handed up and nobody has answered — oldest first, so
     * the one waiting longest is at the top.
     *
     * @return array<int, array<string, mixed>>
     */
    public function escalatedToPlatform(int $limit = 100): array
    {
        return $this->tickets->escalatedToPlatform($limit);
    }

    /** How many are waiting on us — for a badge somewhere on the platform's side. */
    public function escalatedCount(): int
    {
        return count($this->tickets->escalatedToPlatform(500));
    }
}
