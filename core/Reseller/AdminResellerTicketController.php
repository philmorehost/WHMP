<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

/**
 * Tickets the stores have handed up, and how long they have been waiting.
 *
 * This is a WORK LIST, not a report. It is ordered oldest-first by escalated_at, so the
 * ticket that has been waiting longest is at the top — which is the only ordering that
 * makes a queue useful.
 *
 * THERE IS NO "ANSWER FROM HERE" BUTTON, deliberately. The platform already has a full
 * ticket page at /admin/tickets/{id} with replies, attachments, assignment and
 * department changes, and it is where an admin does everything else with a ticket. A
 * second, thinner reply box here would be a second place for a reply to come from, and
 * `TicketService::reply()` only clears an escalation when the author type is `admin` —
 * so the queue is emptied by answering properly rather than by a shortcut that would
 * have to duplicate that logic. Every row links straight there.
 */
final class AdminResellerTicketController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly SessionManager $session,
        private readonly ResellerTicketService $tickets
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $queue = $this->tickets->escalatedToPlatform();

        return $this->render('reseller.admin-escalations', [
            'queue' => $queue,
            'waiting' => $this->ageing($queue),
            'oldest' => $queue === [] ? null : (string) ($queue[0]['escalated_at'] ?? ''),
            'notice' => $this->session->pullFlash('reseller_notice'),
            'error' => $this->session->pullFlash('reseller_error'),
        ]);
    }

    // ------------------------------------------------------------- internals ---

    /**
     * How long each one has been waiting, in whole hours.
     *
     * Computed here rather than in the view so the same figure can later feed a
     * dashboard badge without a second implementation of "how overdue is this".
     *
     * A missing or unparseable escalated_at yields NULL rather than 0: zero would read
     * as "just arrived", which is the one thing it definitely is not.
     *
     * @param array<int, array<string, mixed>> $queue
     * @return array<int, int|null> keyed by ticket id
     */
    private function ageing(array $queue): array
    {
        $now = new \DateTimeImmutable();
        $ages = [];

        foreach ($queue as $row) {
            $stamp = (string) ($row['escalated_at'] ?? '');
            $at = $stamp === '' ? false : \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $stamp);

            if ($at === false) {
                $ages[(int) $row['id']] = null;

                continue;
            }

            $ages[(int) $row['id']] = (int) floor(($now->getTimestamp() - $at->getTimestamp()) / 3600);
        }

        return $ages;
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::RESELLERS_MANAGE)) {
            return Response::html('403 Forbidden — missing resellers.manage permission', 403);
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Store escalations',
            'content' => $content,
        ]));
    }
}
