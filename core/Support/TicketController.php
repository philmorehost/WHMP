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

    /**
     * Closes the selected tickets.
     *
     * Each one goes through TicketService::close() rather than a single bulk
     * UPDATE, so the TicketClose hook fires per ticket exactly as it does for
     * the single-ticket button — anything listening on it (notifications,
     * satisfaction surveys, addons) would otherwise silently stop firing for
     * bulk actions only.
     *
     * Tickets already closed are skipped rather than re-closed, so the count
     * reported back is the number that actually changed.
     */
    public function bulkClose(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $requested = array_map('intval', (array) $request->input('ticket_ids', []));
        $toClose = $this->tickets->openIdsAmong($requested);

        $closed = 0;

        foreach ($toClose as $id) {
            try {
                $this->ticketService->close($id);
                $closed++;
            } catch (\Throwable) {
                // A listener throwing must not abandon the rest of the batch.
                continue;
            }
        }

        if ($closed > 0) {
            $this->activity->log(
                'admin',
                (int) $this->guard->currentAdmin()['id'],
                'ticket.bulk_closed',
                'ticket',
                null,
                "Closed {$closed} ticket(s)",
                $request->ip()
            );
        }

        $skipped = max(0, count(array_unique(array_filter($requested))) - $closed);

        return Response::redirect('/admin/tickets?closed=' . $closed . '&close_skipped=' . $skipped);
    }

    /**
     * Permanently deletes the selected tickets.
     *
     * Replies and attachment records go with them via the foreign keys'
     * cascade, but the uploaded files on disk are not covered by that — so
     * their names are collected first and the files removed afterwards.
     * Skipping that would leave every deleted ticket's uploads on disk with
     * nothing left pointing at them.
     *
     * There is no undo: tickets carry the client's own correspondence, so the
     * confirmation says so plainly and the action is written to the audit log.
     */
    public function bulkDelete(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $ids = array_map('intval', (array) $request->input('ticket_ids', []));

        // Read the filenames while the rows still exist.
        $files = $this->tickets->attachmentFilesFor($ids);

        $deleted = $this->tickets->deleteMany($ids);
        $filesRemoved = $deleted > 0 ? $this->attachmentService->deleteFiles($files) : 0;

        if ($deleted > 0) {
            $this->activity->log(
                'admin',
                (int) $this->guard->currentAdmin()['id'],
                'ticket.bulk_deleted',
                'ticket',
                null,
                "Deleted {$deleted} ticket(s) and {$filesRemoved} attachment file(s)",
                $request->ip()
            );
        }

        return Response::redirect('/admin/tickets?deleted=' . $deleted . '&files=' . $filesRemoved);
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
            'deletedCount' => $request->query('deleted') !== null ? max(0, (int) $request->query('deleted')) : null,
            'deletedFiles' => max(0, (int) $request->query('files', 0)),
            'closedCount' => $request->query('closed') !== null ? max(0, (int) $request->query('closed')) : null,
            'closeSkipped' => max(0, (int) $request->query('close_skipped', 0)),
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

        // Merged away — the conversation now lives on the target ticket, so
        // a stale link/bookmark to this id should land there, not on an
        // empty, closed shell of a ticket.
        if ($ticket['merged_into_id'] !== null) {
            return Response::redirect('/admin/tickets/' . (int) $ticket['merged_into_id']);
        }

        $mergeConfirmTargetId = $request->query('merge_confirm_target') !== null ? (int) $request->query('merge_confirm_target') : null;
        $mergedFromId = $request->query('merged_from') !== null ? (int) $request->query('merged_from') : null;

        return $this->render('support.ticket-show', $this->ticketShowData($ticket, null, null, [
            'mergeError' => $request->query('merge_error'),
            'mergeConfirmTargetId' => $mergeConfirmTargetId,
            // Fetched here (not just the id) so the confirm banner can show
            // whose ticket the admin is about to merge into — the whole
            // point of asking for confirmation in the first place.
            'mergeConfirmTargetTicket' => $mergeConfirmTargetId !== null ? $this->tickets->find($mergeConfirmTargetId) : null,
            'mergedFromId' => $mergedFromId,
            'mergedFromTicket' => $mergedFromId !== null ? $this->tickets->find($mergedFromId) : null,
            'mergeCrossClientNotice' => $request->query('merge_cross_client') === '1',
            'mergeTargetPrefill' => $request->query('merge_target_prefill') !== null ? (int) $request->query('merge_target_prefill') : null,
        ]));
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
            return $this->render('support.ticket-show', $this->ticketShowData(
                $ticket,
                null,
                'AI ticket-reply drafting is turned off. An admin can enable it under Configuration → AI Copilot.'
            ));
        }

        $conversation = array_map(
            static fn (array $reply) => ['author' => $reply['author_type'], 'message' => $reply['message']],
            $this->replies->forTicket($id, includePrivate: false)
        );

        $result = $this->aiProvider->complete(...$this->buildReplyPrompts($conversation));

        return $this->render('support.ticket-show', $this->ticketShowData(
            $ticket,
            $result['success'] ? $result['text'] : null,
            $result['success'] ? null : $result['error']
        ));
    }

    /**
     * Merges this ticket into another one. Same-client merges (the intended
     * case) go through immediately; a merge across two different clients —
     * almost always a mis-typed ticket number — first redirects back with a
     * warning instead of merging, and only proceeds once the admin resubmits
     * with confirm_cross_client=1. Either way the resulting merge is logged
     * and the survivor's page shows a "different clients" notice, so a
     * mistake made anyway is still visible after the fact.
     */
    public function merge(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $sourceId = (int) $params['id'];
        $targetId = (int) $request->input('target_ticket_id', 0);
        $confirmed = (string) $request->input('confirm_cross_client', '') === '1';

        if ($targetId <= 0 || $targetId === $sourceId) {
            return Response::redirect("/admin/tickets/{$sourceId}?merge_error=" . urlencode('Enter a different ticket # to merge into.'));
        }

        $source = $this->tickets->find($sourceId);
        $target = $this->tickets->find($targetId);

        if ($source === null || $target === null) {
            return Response::redirect("/admin/tickets/{$sourceId}?merge_error=" . urlencode('That ticket # does not exist.'));
        }

        if (!$confirmed && $this->ticketService->isCrossClientMerge($source, $target)) {
            return Response::redirect("/admin/tickets/{$sourceId}?merge_confirm_target={$targetId}");
        }

        $admin = $this->guard->currentAdmin();
        $result = $this->ticketService->merge($sourceId, $targetId, (int) $admin['id'], $admin['display_name']);

        if (!$result['success']) {
            return Response::redirect("/admin/tickets/{$sourceId}?merge_error=" . urlencode((string) $result['error']));
        }

        $this->activity->log(
            'admin',
            (int) $admin['id'],
            'ticket.merged',
            'ticket',
            $sourceId,
            "Merged ticket #{$sourceId} into #{$targetId}" . ($result['crossClient'] ? ' — different clients' : ''),
            $request->ip()
        );

        $crossClientFlag = $result['crossClient'] ? '&merge_cross_client=1' : '';

        return Response::redirect("/admin/tickets/{$targetId}?merged_from={$sourceId}{$crossClientFlag}");
    }

    /**
     * Shared support.ticket-show payload — show(), aiSuggest(), and merge()'s
     * cross-client confirm redirect all render this same view.
     *
     * @param array<string, mixed> $ticket
     * @param array<string, mixed> $extra merged in last, so callers can add/override view-specific flags
     * @return array<string, mixed>
     */
    private function ticketShowData(array $ticket, ?string $aiSuggestion, ?string $aiError, array $extra = []): array
    {
        $id = (int) $ticket['id'];

        // Other open merge targets for the same client — the intended,
        // no-warning-needed case — so the merge form can offer them as a
        // picker instead of making the admin look up a ticket number first.
        $sameClientTickets = [];
        if ($ticket['client_id'] !== null) {
            $sameClientTickets = array_values(array_filter(
                $this->tickets->forClient((int) $ticket['client_id']),
                static fn (array $t): bool => (int) $t['id'] !== $id && $t['merged_into_id'] === null
            ));
        }

        return array_merge([
            'ticket' => $ticket,
            'replies' => $this->replies->forTicket($id, includePrivate: true),
            'departments' => $this->departments->all(),
            'cannedReplies' => $this->cannedReplies->all(),
            'admins' => $this->admins->all(),
            'attachments' => $this->attachments->forTicketGroupedByReply($id),
            'sameClientTickets' => $sameClientTickets,
            'aiSuggestion' => $aiSuggestion,
            'aiError' => $aiError,
            'mergeError' => null,
            'mergeConfirmTargetId' => null,
            'mergeConfirmTargetTicket' => null,
            'mergedFromId' => null,
            'mergedFromTicket' => null,
            'mergeCrossClientNotice' => false,
            'mergeTargetPrefill' => null,
        ], $extra);
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
