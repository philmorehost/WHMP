<?php

declare(strict_types=1);

namespace CodeVault\Mail;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;

/**
 * "My Emails" — the client-area counterpart to the admin Email Log
 * (blueprint §4.1). Every transactional email sent to this account
 * (invoices, renewals, dunning, tickets, campaigns) shows up here as a
 * read-only history row. The EmailLogRepository::forClient() query that
 * backs it predates this page; the route and view are the missing half.
 */
final class ClientEmailLogController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly EmailLogRepository $emails
    ) {
    }

    public function index(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(10, min(100, (int) $request->query('per_page', 25)));
        $offset = ($page - 1) * $perPage;

        $db = \CodeVault\Support\App::container()->make(\CodeVault\Database::class);
        $total = (int) ($db->selectOne(
            'SELECT COUNT(*) AS c FROM email_log WHERE client_id = ?',
            [(int) $client['id']]
        )['c'] ?? 0);

        $emails = $db->select(
            'SELECT id, to_email, subject, template_key, status, error, created_at, sent_at FROM email_log WHERE client_id = ? ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            [(int) $client['id']]
        );

        $content = $this->view->render('mail.client-email-log', [
            'emails' => $emails,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'pages' => max(1, (int) ceil($total / $perPage)),
            ],
            'error' => $request->query('error'),
        ]);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'My Emails',
            'content' => $content,
        ]));
    }
}
