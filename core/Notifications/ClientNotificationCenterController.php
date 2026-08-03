<?php

declare(strict_types=1);

namespace CodeVault\Notifications;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Support\DepartmentRepository;
use CodeVault\Support\TicketService;
use CodeVault\View;

/**
 * Client-facing notification center — every notification an admin sent
 * this client directly, plus a mirror of every system email addressed to
 * them (see EmailDispatcher), so a client whose registered email never
 * actually delivers (a common failure mode for custom/expired domains)
 * still sees the same content the moment they log in. Replying turns the
 * notification into a support ticket, the same as replying to a real email
 * would.
 */
final class ClientNotificationCenterController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly ClientNotificationRepository $notifications,
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

        return $this->page('client-notifications.index', [
            'notifications' => $this->notifications->forClient((int) $client['id']),
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $notification = $this->notifications->findForClient((int) $params['id'], (int) $client['id']);

        if ($notification === null) {
            return Response::html('404 Not Found', 404);
        }

        if ($notification['read_at'] === null) {
            $this->notifications->markRead((int) $notification['recipient_id']);
            $notification['read_at'] = date('Y-m-d H:i:s');
        }

        return $this->page('client-notifications.show', [
            'notification' => $notification,
        ]);
    }

    public function reply(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $notification = $this->notifications->findForClient((int) $params['id'], (int) $client['id']);

        if ($notification === null) {
            return Response::html('404 Not Found', 404);
        }

        $message = trim((string) $request->input('message', ''));

        if ($message === '') {
            return Response::redirect('/client/notifications/' . (int) $notification['id']);
        }

        // No inbound "to" address to route by department here (unlike
        // MailPipingJob, which has a real email to match against
        // departments.email) — a reply to an in-app notification always
        // falls back to the first department, same as MailPipingJob does
        // for mail it can't otherwise route.
        $department = $this->departments->all()[0] ?? null;

        if ($department === null) {
            return Response::redirect('/client/notifications/' . (int) $notification['id']);
        }

        // The notification body may be the raw HTML of a mirrored email
        // (see EmailDispatcher) — a ticket message is plain text, so quote
        // it stripped of markup rather than showing literal <p> tags.
        $quotedBody = trim(html_entity_decode(strip_tags((string) $notification['body']), ENT_QUOTES, 'UTF-8'));

        $ticketId = $this->ticketService->open(
            (int) $client['id'],
            (string) $client['email'],
            (int) $department['id'],
            'Re: ' . $notification['subject'],
            trim((string) $client['first_name'] . ' ' . (string) $client['last_name']),
            $message . "\n\n---\nIn reply to notification: \"{$notification['subject']}\"\n\n" . $quotedBody
        );

        $this->notifications->recordReplyTicket((int) $notification['recipient_id'], $ticketId);

        return Response::redirect("/client/tickets/{$ticketId}");
    }

    /** @param array<string, mixed> $data */
    private function page(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'Notifications',
            'content' => $content,
        ]));
    }
}
