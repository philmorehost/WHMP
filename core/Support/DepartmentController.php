<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class DepartmentController
{
    /**
     * Tickets removed per batch, and how long a single purge request is
     * allowed to keep going. Shared hosting kills long requests, so a big
     * clean-up finishes across several clicks instead of timing out halfway
     * with no idea what happened.
     */
    private const PURGE_BATCH = 200;
    private const PURGE_SECONDS = 15;

    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly DepartmentRepository $departments,
        private readonly TicketRepository $tickets,
        private readonly TicketAttachmentService $attachmentService,
        private readonly ActivityLogger $activity
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $departments = $this->departments->all();

        // Ticket counts drive the delete UI: a department holding tickets needs
        // a destination chosen before it can go.
        foreach ($departments as &$department) {
            $department['ticket_count'] = $this->departments->ticketCount((int) $department['id']);
        }
        unset($department);

        $content = $this->view->render('support.departments-index', [
            'departments' => $departments,
            'error' => $request->query('error'),
            'deleted' => $request->query('deleted') === '1',
            'movedCount' => max(0, (int) $request->query('moved', 0)),
            'purgeScopes' => TicketRepository::purgeScopes(),
            'purged' => $request->query('purged') === null ? null : max(0, (int) $request->query('purged', 0)),
            'purgedFiles' => max(0, (int) $request->query('files', 0)),
            'purgeRemaining' => max(0, (int) $request->query('remaining', 0)),
        ]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Departments',
            'content' => $content,
        ]));
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $name = trim((string) $request->input('name', ''));
        $email = trim((string) $request->input('email', '')) ?: null;

        if ($name !== '') {
            $this->departments->create($name, $email);
        }

        return Response::redirect('/admin/departments');
    }

    public function update(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $name = trim((string) $request->input('name', ''));
        $email = trim((string) $request->input('email', '')) ?: null;

        if ($name !== '') {
            $this->departments->update((int) $params['id'], $name, $email);
        }

        return Response::redirect('/admin/departments');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];

        // Never leave the install with nowhere to file a ticket. Checked first
        // so a doomed delete doesn't shuffle tickets around on the way out.
        if (count($this->departments->all()) <= 1) {
            return Response::redirect('/admin/departments?error=' . urlencode(
                'You cannot delete the last department — tickets must belong to one.'
            ));
        }

        $ticketCount = $this->departments->ticketCount($id);

        // tickets.department_id is NOT NULL with a RESTRICT foreign key, so a
        // department holding tickets cannot simply be deleted — the database
        // rejects it and the raw PDOException reached the browser as a fatal
        // error page. Handle it here instead: either move the tickets
        // somewhere the admin chooses, or explain why the delete can't happen.
        if ($ticketCount > 0) {
            $moveTo = (int) $request->input('move_to', 0);
            $target = $moveTo > 0 ? $this->departments->find($moveTo) : null;

            if ($target === null || $moveTo === $id) {
                return Response::redirect('/admin/departments?error=' . urlencode(
                    "That department still has {$ticketCount} ticket(s). Choose a department to move them to, then delete it."
                ));
            }

            $this->departments->reassignTickets($id, $moveTo);
        }

        try {
            $this->departments->delete($id);
        } catch (\Throwable $e) {
            // Anything still referencing it (an addon table, a future feature)
            // surfaces as a readable message rather than a stack trace.
            return Response::redirect('/admin/departments?error=' . urlencode(
                'That department is still in use and could not be deleted.'
            ));
        }

        $moved = $ticketCount > 0 ? "&moved={$ticketCount}" : '';

        return Response::redirect('/admin/departments?deleted=1' . $moved);
    }

    /**
     * Empties a department of tickets.
     *
     * Replies and attachment rows follow via the foreign keys' CASCADE; the
     * uploaded files on disk do not, so their names are read while the rows
     * still exist and the files are removed afterwards.
     *
     * There is no undo — this is the client's own correspondence — so the
     * scope is explicit, the confirmation states the count, and the run is
     * written to the audit log.
     */
    public function purge(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $department = $this->departments->find($id);

        if ($department === null) {
            return Response::redirect('/admin/departments?error=' . urlencode('That department no longer exists.'));
        }

        $scope = (string) $request->input('scope', '');

        if (!TicketRepository::isPurgeScope($scope)) {
            return Response::redirect('/admin/departments?error=' . urlencode('Choose what to clear before emptying a department.'));
        }

        $deleted = 0;
        $filesRemoved = 0;
        $deadline = time() + self::PURGE_SECONDS;

        // Batched so a department with thousands of tickets doesn't hit the
        // request timeout mid-cascade. Whatever is left over is reported back
        // and cleared by running it again.
        while (time() < $deadline) {
            $ids = $this->tickets->idsInDepartmentScope($id, $scope, self::PURGE_BATCH);

            if ($ids === []) {
                break;
            }

            $files = $this->tickets->attachmentFilesFor($ids);
            $removed = $this->tickets->deleteMany($ids);

            if ($removed === 0) {
                break;
            }

            $deleted += $removed;
            $filesRemoved += $this->attachmentService->deleteFiles($files);
        }

        $remaining = $this->tickets->countInDepartmentScope($id, $scope);

        if ($deleted > 0) {
            $this->activity->log(
                'admin',
                (int) $this->guard->currentAdmin()['id'],
                'department.tickets_purged',
                'department',
                $id,
                sprintf(
                    'Emptied "%s" (%s): deleted %d ticket(s) and %d attachment file(s), %d remaining',
                    (string) $department['name'],
                    $scope,
                    $deleted,
                    $filesRemoved,
                    $remaining
                ),
                $request->ip()
            );
        }

        return Response::redirect(sprintf(
            '/admin/departments?purged=%d&files=%d&remaining=%d',
            $deleted,
            $filesRemoved,
            $remaining
        ));
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
}
