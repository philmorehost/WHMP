<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\View;

/**
 * The store's own support desk: its customers' tickets, and a way to hand one up.
 *
 * Deliberately a SEPARATE controller rather than more methods on
 * ClientResellerController. That class has thirteen constructor dependencies and is
 * built by hand in two test files, so a fourteenth would silently rebind every
 * argument after it in both. This one is container-built and free to grow — the same
 * reasoning that put the cost-billing surfaces in AdminResellerBillingController.
 *
 * NOT ONE ACTION TRUSTS A STORE ID FROM THE REQUEST.
 *
 * The client comes from the session guard, ResellerTicketService resolves the store
 * from that client, and every ticket lookup is scoped INSIDE the query
 * (TicketRepository::forResellerTicket). A ticket id in the URL is therefore an id
 * that has already been checked against this store, not a claim about ownership —
 * which is what makes one store unable to open another's conversation.
 *
 * "NOT YOURS" AND "DOES NOT EXIST" GET THE SAME ANSWER, on purpose: distinguishing
 * them confirms which ticket ids exist, which is information about other stores.
 */
final class ClientResellerTicketController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly SessionManager $session,
        private readonly ResellerTicketService $tickets
    ) {
    }

    /** The store's queue, in the platform's own order so the two cannot disagree. */
    public function index(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $clientId = (int) $client['id'];
        $store = $this->tickets->storeForClient($clientId);

        // No store means no customers, so there is nothing to show. Sending them to
        // open one is more useful than an empty queue with no explanation.
        if ($store === null) {
            return Response::redirect('/client/reseller');
        }

        $tickets = $this->tickets->ticketsForClient($clientId);

        return $this->page('reseller.client-tickets', [
            'store' => $store,
            'tickets' => $tickets,
            'counts' => $this->counts($tickets),
            'notice' => $this->session->pullFlash('reseller_notice'),
            'error' => $this->session->pullFlash('reseller_error'),
        ]);
    }

    /** One conversation, with the reply and escalation controls. */
    public function show(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $clientId = (int) $client['id'];
        $store = $this->tickets->storeForClient($clientId);
        $ticketId = (int) ($params['ticketId'] ?? 0);

        $ticket = $this->tickets->ticketForClient($clientId, $ticketId);

        if ($store === null || $ticket === null) {
            $this->session->flash('reseller_error', 'That ticket is not available on your account.');

            return Response::redirect('/client/reseller/tickets');
        }

        return $this->page('reseller.client-ticket', [
            'store' => $store,
            'ticket' => $ticket,
            'replies' => $this->tickets->repliesForClient($clientId, $ticketId),
            'authorName' => $this->authorName($client, $store),
            'notice' => $this->session->pullFlash('reseller_notice'),
            'error' => $this->session->pullFlash('reseller_error'),
        ]);
    }

    /**
     * Answer the customer.
     *
     * The reply is recorded as authored by the STORE, and signed with the store's
     * brand name so the customer sees the same name as everywhere else on the site.
     */
    public function reply(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $clientId = (int) $client['id'];
        $ticketId = (int) ($params['ticketId'] ?? 0);
        $store = $this->tickets->storeForClient($clientId);

        $result = $this->tickets->reply(
            $clientId,
            $ticketId,
            (string) $request->input('message', ''),
            $this->authorName($client, $store)
        );

        $this->flash($result, 'Reply sent to your customer.');

        return Response::redirect('/client/reseller/tickets/' . $ticketId);
    }

    /** Hand the ticket to the platform, with a note saying what was already tried. */
    public function escalate(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $ticketId = (int) ($params['ticketId'] ?? 0);

        $result = $this->tickets->escalate(
            (int) $client['id'],
            $ticketId,
            (string) $request->input('note', '')
        );

        $this->flash($result, 'Passed to support — you will be notified when they reply.');

        return Response::redirect('/client/reseller/tickets/' . $ticketId);
    }

    /** Take it back: the store resolved it without us after all. */
    public function withdraw(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $ticketId = (int) ($params['ticketId'] ?? 0);

        $result = $this->tickets->withdraw((int) $client['id'], $ticketId);

        $this->flash($result, 'Taken back from support.');

        return Response::redirect('/client/reseller/tickets/' . $ticketId);
    }

    // ------------------------------------------------------------- internals ---

    /**
     * How the store signs a reply.
     *
     * The brand name when it has one, because that is the name the customer already
     * sees on the storefront and in the chat widget — a reply signed with the
     * owner's personal name would look like it came from somewhere else.
     *
     * @param array<string, mixed>      $client
     * @param array<string, mixed>|null $store
     */
    private function authorName(array $client, ?array $store): string
    {
        $brand = trim((string) ($store['brand_name'] ?? ''));

        if ($brand !== '') {
            return $brand;
        }

        $person = trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? ''));

        return $person !== '' ? $person : 'Support';
    }

    /**
     * Queue counts for the header.
     *
     * "Needs you" is open + customer-reply, which is exactly the pair the platform's
     * own list puts first — a ticket the customer has replied to is waiting on the
     * store, and showing it as merely "open" would hide the newer of the two.
     *
     * @param array<int, array<string, mixed>> $tickets
     * @return array{total: int, needsReply: int, escalated: int, closed: int}
     */
    private function counts(array $tickets): array
    {
        $total = count($tickets);
        $needsReply = 0;
        $escalated = 0;
        $closed = 0;

        foreach ($tickets as $ticket) {
            $status = (string) ($ticket['status'] ?? '');

            if (in_array($status, ['open', 'customer-reply'], true)) {
                $needsReply++;
            }

            if ($status === 'closed') {
                $closed++;
            }

            if (($ticket['escalated_at'] ?? null) !== null && $status !== 'closed') {
                $escalated++;
            }
        }

        return [
            'total' => $total,
            'needsReply' => $needsReply,
            'escalated' => $escalated,
            'closed' => $closed,
        ];
    }

    /**
     * @param array{success: bool, error: ?string} $result
     */
    private function flash(array $result, string $successMessage): void
    {
        $this->session->flash(
            $result['success'] ? 'reseller_notice' : 'reseller_error',
            $result['success'] ? $successMessage : (string) $result['error']
        );
    }

    /** @param array<string, mixed> $data */
    private function page(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'Store Support',
            'content' => $content,
        ]));
    }
}
