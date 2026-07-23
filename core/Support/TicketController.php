<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Ai\AiProvider;
use CodeVault\Ai\PiiRedactor;
use CodeVault\Auth\AuthGuard;
use CodeVault\Billing\BillableItemRepository;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\Auth\AdminRepository;
use CodeVault\View;

final class TicketController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly TicketRepository $tickets,
        private readonly TicketReplyRepository $replies,
        private readonly DepartmentRepository $departments,
        private readonly CannedReplyRepository $cannedReplies,
        private readonly AdminRepository $admins,
        private readonly TicketService $ticketService,
        private readonly ActivityLogger $activity,
        private readonly BillableItemRepository $billableItems,
        private readonly AiProvider $aiProvider,
        private readonly \CodeVault\Ai\AiSettings $aiSettings,
        private readonly TicketAttachmentRepository $attachments,
        private readonly TicketAttachmentService $attachmentService
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $status = (string) $request->query('status', '');
        $departmentId = $request->query('department_id');
        $page = max(1, (int) $request->query('page', 1));

        $filters = [];
        if ($status !== '') {
            $filters['status'] = $status;
        }
        if ($departmentId !== null && $departmentId !== '') {
            $filters['departmentId'] = (int) $departmentId;
        }

        $pagination = $this->tickets->paginate($filters, $page, 20);

        return $this->render('support.tickets-index', [
            'tickets' => $pagination['data'],
            'pagination' => $pagination,
            'departments' => $this->departments->all(),
            'statusFilter' => $status,
            'departmentFilter' => $departmentId,
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $ticket = $this->tickets->find((int) $params['id']);

        if ($ticket === null) {
            return Response::html('404 Not Found', 404);
        }

        return $this->render('support.ticket-show', [
            'ticket' => $ticket,
            'replies' => $this->replies->forTicket((int) $ticket['id'], includePrivate: true),
            'departments' => $this->departments->all(),
            'cannedReplies' => $this->cannedReplies->all(),
            'admins' => $this->admins->all(),
            'attachments' => $this->attachments->forTicketGroupedByReply((int) $ticket['id']),
            'aiSuggestion' => null,
            'aiError' => null,
        ]);
    }

    public function aiSuggest(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $ticket = $this->tickets->find($id);

        if ($ticket === null) {
            return Response::html('404 Not Found', 404);
        }

        if (!$this->aiSettings->isFeatureEnabled('ticket_replies')) {
            return $this->render('support.ticket-show', [
                'ticket' => $ticket,
                'replies' => $this->replies->forTicket($id, includePrivate: true),
                'departments' => $this->departments->all(),
                'cannedReplies' => $this->cannedReplies->all(),
                'admins' => $this->admins->all(),
            'attachments' => $this->attachments->forTicketGroupedByReply((int) $ticket['id']),
                'aiSuggestion' => null,
                'aiError' => 'AI ticket-reply drafting is turned off. An admin can enable it under Configuration → AI Copilot.',
            ]);
        }

        $conversation = array_map(
            static fn (array $reply) => ['author' => $reply['author_type'], 'message' => $reply['message']],
            $this->replies->forTicket($id, includePrivate: false)
        );

        $result = $this->aiProvider->complete(...$this->buildReplyPrompts($conversation));

        return $this->render('support.ticket-show', [
            'ticket' => $ticket,
            'replies' => $this->replies->forTicket($id, includePrivate: true),
            'departments' => $this->departments->all(),
            'cannedReplies' => $this->cannedReplies->all(),
            'admins' => $this->admins->all(),
            'attachments' => $this->attachments->forTicketGroupedByReply((int) $ticket['id']),
            'aiSuggestion' => $result['success'] ? $result['text'] : null,
            'aiError' => $result['success'] ? null : $result['error'],
        ]);
    }

    /**
     * @param array<int, array{author: string, message: string}> $conversation oldest-first
     * @return array{0: string, 1: string} [systemPrompt, userPrompt]
     */
    private function buildReplyPrompts(array $conversation): array
    {
        $systemPrompt = 'You are a support agent assistant for a web hosting and domain company. '
            . 'Draft a concise, professional reply to the customer\'s latest message in the ticket transcript below. '
            . 'Reply with only the message body — no greeting, no signature, no explanation of what you did.';

        $transcript = [];

        foreach ($conversation as $turn) {
            $speaker = $turn['author'] === 'admin' ? 'Support' : 'Customer';
            $transcript[] = "{$speaker}: " . PiiRedactor::redact($turn['message']);
        }

        return [$systemPrompt, implode("\n\n", $transcript)];
    }

    public function reply(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $message = trim((string) $request->input('message', ''));
        $isPrivate = (bool) $request->input('is_private', false);
        $admin = $this->guard->currentAdmin();

        $filesEntry = $request->file('attachments');
        $hasFiles = $this->attachmentService->hasRealUpload($filesEntry);

        if ($message !== '' || $hasFiles) {
            $replyId = $this->ticketService->reply($id, 'admin', (int) $admin['id'], $admin['display_name'], $message, $isPrivate);

            if ($hasFiles) {
                $this->attachmentService->storeFromFilesEntry($filesEntry, $id, $replyId, 'admin');
            }

            $this->activity->log('admin', (int) $admin['id'], 'ticket.replied', 'ticket', $id, $isPrivate ? "Added a private note to ticket #{$id}" : "Replied to ticket #{$id}", $request->ip());
        }

        return Response::redirect("/admin/tickets/{$id}");
    }

    public function attachment(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $attachment = $this->attachments->find((int) $params['attId']);

        if ($attachment === null || (int) $attachment['ticket_id'] !== (int) $params['id']) {
            return Response::html('404 Not Found', 404);
        }

        return $this->serveAttachment($attachment);
    }

    /** @param array<string, mixed> $attachment */
    private function serveAttachment(array $attachment): Response
    {
        $file = $this->attachmentService->fileFor($attachment);

        if ($file === null) {
            return Response::html('404 Not Found', 404);
        }

        // Inline for raster images + PDF so the browser previews them.
        // SVG is deliberately excluded (it can carry scripts) and served as a
        // download; nosniff stops any file being reinterpreted as HTML/JS.
        $previewable = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/tiff', 'application/pdf'];
        $disposition = in_array($file['mime'], $previewable, true) ? 'inline' : 'attachment';
        $bytes = (string) file_get_contents($file['path']);

        return (new Response($bytes, 200))
            ->withHeader('Content-Type', $file['mime'])
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Disposition', $disposition . '; filename="' . str_replace('"', '', $file['name']) . '"')
            ->withHeader('Content-Length', (string) strlen($bytes));
    }

    public function close(Request $request, array $params): Response
    {
        return $this->transition($request, $params, fn (int $id) => $this->ticketService->close($id), 'ticket.closed', 'Closed ticket');
    }

    public function reopen(Request $request, array $params): Response
    {
        return $this->transition($request, $params, fn (int $id) => $this->ticketService->reopen($id), 'ticket.reopened', 'Reopened ticket');
    }

    public function assign(Request $request, array $params): Response
    {
        $adminId = $request->input('admin_id');
        $adminId = $adminId !== null && $adminId !== '' ? (int) $adminId : null;

        return $this->transition($request, $params, fn (int $id) => $this->tickets->assign($id, $adminId), 'ticket.assigned', $adminId === null ? 'Unassigned ticket' : 'Assigned ticket');
    }

    public function setPriority(Request $request, array $params): Response
    {
        $priority = (string) $request->input('priority', 'medium');

        return $this->transition($request, $params, fn (int $id) => $this->tickets->setPriority($id, $priority), 'ticket.priority_changed', "Set priority to {$priority}");
    }

    public function setDepartment(Request $request, array $params): Response
    {
        $departmentId = (int) $request->input('department_id', 0);

        return $this->transition($request, $params, fn (int $id) => $this->tickets->setDepartment($id, $departmentId), 'ticket.department_changed', 'Moved ticket to a different department');
    }

    public function convertToBillable(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $ticket = $this->tickets->find($id);

        if ($ticket === null || $ticket['client_id'] === null) {
            return Response::redirect("/admin/tickets/{$id}");
        }

        $description = trim((string) $request->input('description', ''));
        $amount = (float) $request->input('amount', 0);

        if ($description !== '' && $amount > 0) {
            $this->billableItems->create((int) $ticket['client_id'], $description, $amount, 'ticket', $id);
            $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], 'ticket.billable_created', 'ticket', $id, "Created a billable item ({$description}) from ticket #{$id}", $request->ip());
        }

        return Response::redirect("/admin/tickets/{$id}");
    }

    private function transition(Request $request, array $params, callable $action, string $logAction, string $description): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $action($id);
        $this->activity->log('admin', (int) $this->guard->currentAdmin()['id'], $logAction, 'ticket', $id, "{$description} (#{$id})", $request->ip());

        return Response::redirect("/admin/tickets/{$id}");
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::TICKETS_MANAGE)) {
            return Response::html('403 Forbidden — missing tickets.manage permission', 403);
        }

        return null;
    }

    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Support',
            'content' => $content,
        ]));
    }
}
