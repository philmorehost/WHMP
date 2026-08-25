<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;

final class ClientTicketController
{
    /**
     * Max open (non-closed) tickets a single client account may hold before
     * the client ticket form refuses to open another — stops one account
     * flooding the admin queue while still letting real support requests
     * through.
     */
    private const MAX_OPEN_TICKETS_PER_CLIENT = 5;

    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly TicketRepository $tickets,
        private readonly TicketReplyRepository $replies,
        private readonly DepartmentRepository $departments,
        private readonly TicketService $ticketService,
        private readonly TicketAttachmentRepository $attachments,
        private readonly TicketAttachmentService $attachmentService
    ) {
    }

    public function index(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        return $this->page('support.client-tickets-index', [
            'tickets' => $this->tickets->forClient((int) $client['id']),
        ]);
    }

    public function create(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        return $this->page('support.client-ticket-create', [
            'departments' => $this->departments->all(),
            'limitReached' => $request->query('limit') === '1',
            'maxOpenTickets' => self::MAX_OPEN_TICKETS_PER_CLIENT,
        ]);
    }

    public function store(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        // Flood guard: cap open tickets per client account so a single
        // account can't keep opening tickets and bury the admin queue.
        if ($this->tickets->countOpenByClient((int) $client['id']) >= self::MAX_OPEN_TICKETS_PER_CLIENT) {
            return Response::redirect('/client/tickets/create?limit=1');
        }

        $departmentId = (int) $request->input('department_id', 0);
        $subject = trim((string) $request->input('subject', ''));
        $message = trim((string) $request->input('message', ''));

        if ($departmentId <= 0 || $subject === '' || $message === '') {
            return Response::redirect('/client/tickets/create');
        }

        $ticketId = $this->ticketService->open(
            (int) $client['id'],
            (string) $client['email'],
            $departmentId,
            $subject,
            (string) $client['first_name'] . ' ' . (string) $client['last_name'],
            $message
        );

        // Attachments on the opening message belong to the ticket itself
        // (no reply row) — reply_id null, group key 0.
        $filesEntry = $request->file('attachments');
        if ($this->attachmentService->hasRealUpload($filesEntry)) {
            $this->attachmentService->storeFromFilesEntry($filesEntry, $ticketId, null, 'client');
        }

        return Response::redirect("/client/tickets/{$ticketId}");
    }

    public function show(Request $request, array $params): Response
    {
        $ticket = $this->ownedTicket($params);

        if ($ticket === null) {
            return $this->deniedOrNotFound();
        }

        if ($ticket['merged_into_id'] !== null) {
            $target = $this->tickets->find((int) $ticket['merged_into_id']);
            $client = $this->guard->currentClient();

            // The normal case: merged into another ticket of this same
            // client's — send them straight there, same as the admin side.
            if ($target !== null && (int) $target['client_id'] === (int) $client['id']) {
                return Response::redirect("/client/tickets/{$target['id']}");
            }

            // A cross-client merge: the target belongs to someone else, so
            // redirecting there would leak another client's ticket. Show
            // this ticket's own (now-empty, closed) shell with a plain
            // explanation instead.
            return $this->page('support.client-ticket-show', [
                'ticket' => $ticket,
                'replies' => [],
                'attachments' => [],
                'mergedAway' => true,
            ]);
        }

        return $this->page('support.client-ticket-show', [
            'ticket' => $ticket,
            'replies' => $this->replies->forTicket((int) $ticket['id'], includePrivate: false),
            'attachments' => $this->attachments->forTicketGroupedByReply((int) $ticket['id']),
        ]);
    }

    public function attachment(Request $request, array $params): Response
    {
        $ticket = $this->ownedTicket($params);

        if ($ticket === null) {
            return $this->deniedOrNotFound();
        }

        $attachment = $this->attachments->find((int) $params['attId']);

        if ($attachment === null || (int) $attachment['ticket_id'] !== (int) $ticket['id']) {
            return $this->deniedOrNotFound();
        }

        $file = $this->attachmentService->fileFor($attachment);

        if ($file === null) {
            return $this->deniedOrNotFound();
        }

        $previewable = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/tiff', 'application/pdf'];
        $disposition = in_array($file['mime'], $previewable, true) ? 'inline' : 'attachment';
        $bytes = (string) file_get_contents($file['path']);

        return (new Response($bytes, 200))
            ->withHeader('Content-Type', $file['mime'])
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Disposition', $disposition . '; filename="' . str_replace('"', '', $file['name']) . '"')
            ->withHeader('Content-Length', (string) strlen($bytes));
    }

    public function reply(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();
        $ticket = $this->ownedTicket($params);

        if ($client === null || $ticket === null) {
            return $this->deniedOrNotFound();
        }

        $message = trim((string) $request->input('message', ''));
        $filesEntry = $request->file('attachments');
        $hasFiles = $this->attachmentService->hasRealUpload($filesEntry);

        if ($message !== '' || $hasFiles) {
            $replyId = $this->ticketService->reply(
                (int) $ticket['id'],
                'client',
                (int) $client['id'],
                (string) $client['first_name'] . ' ' . (string) $client['last_name'],
                $message
            );

            if ($hasFiles) {
                $this->attachmentService->storeFromFilesEntry($filesEntry, (int) $ticket['id'], $replyId, 'client');
            }
        }

        return Response::redirect("/client/tickets/{$ticket['id']}");
    }

    public function rate(Request $request, array $params): Response
    {
        $ticket = $this->ownedTicket($params);

        if ($ticket === null) {
            return $this->deniedOrNotFound();
        }

        $settingsRepo = \CodeVault\Support\App::container()->make(\CodeVault\Settings\SettingsRepository::class);
        if ($settingsRepo->get('support.ticket_rating_enabled', '1') !== '1') {
            return Response::redirect("/client/tickets/{$ticket['id']}");
        }

        $rating = (int) $request->input('rating', 0);
        $replyId = (int) $request->input('reply_id', 0);

        if ($rating >= 1 && $rating <= 5) {
            if ($replyId > 0) {
                // Rate specific staff reply
                \CodeVault\Support\App::container()->make(\CodeVault\Database::class)->update(
                    'UPDATE ticket_replies SET rating = ? WHERE id = ? AND ticket_id = ? AND staff_id IS NOT NULL',
                    [$rating, $replyId, (int) $ticket['id']]
                );
            } elseif ($ticket['status'] === 'closed' && $ticket['satisfaction_rating'] === null) {
                $this->tickets->setRating((int) $ticket['id'], $rating);
            }
        }

        return Response::redirect("/client/tickets/{$ticket['id']}");
    }

    /** @return array<string, mixed>|null */
    private function ownedTicket(array $params): ?array
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return null;
        }

        $ticket = $this->tickets->find((int) $params['id']);

        if ($ticket === null || (int) $ticket['client_id'] !== (int) $client['id']) {
            return null;
        }

        return $ticket;
    }

    private function deniedOrNotFound(): Response
    {
        return $this->guard->check() ? Response::html('404 Not Found', 404) : Response::redirect('/client/login');
    }

    private function page(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'Support Tickets',
            'content' => $content,
        ]));
    }
}
