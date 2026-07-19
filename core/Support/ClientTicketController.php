<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;

final class ClientTicketController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly TicketRepository $tickets,
        private readonly TicketReplyRepository $replies,
        private readonly DepartmentRepository $departments,
        private readonly TicketService $ticketService
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
        ]);
    }

    public function store(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
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

        return Response::redirect("/client/tickets/{$ticketId}");
    }

    public function show(Request $request, array $params): Response
    {
        $ticket = $this->ownedTicket($params);

        if ($ticket === null) {
            return $this->deniedOrNotFound();
        }

        return $this->page('support.client-ticket-show', [
            'ticket' => $ticket,
            'replies' => $this->replies->forTicket((int) $ticket['id'], includePrivate: false),
        ]);
    }

    public function reply(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();
        $ticket = $this->ownedTicket($params);

        if ($client === null || $ticket === null) {
            return $this->deniedOrNotFound();
        }

        $message = trim((string) $request->input('message', ''));

        if ($message !== '') {
            $this->ticketService->reply(
                (int) $ticket['id'],
                'client',
                (int) $client['id'],
                (string) $client['first_name'] . ' ' . (string) $client['last_name'],
                $message
            );
        }

        return Response::redirect("/client/tickets/{$ticket['id']}");
    }

    public function rate(Request $request, array $params): Response
    {
        $ticket = $this->ownedTicket($params);

        if ($ticket === null) {
            return $this->deniedOrNotFound();
        }

        $rating = (int) $request->input('rating', 0);

        if ($ticket['status'] === 'closed' && $ticket['satisfaction_rating'] === null && $rating >= 1 && $rating <= 5) {
            $this->tickets->setRating((int) $ticket['id'], $rating);
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
