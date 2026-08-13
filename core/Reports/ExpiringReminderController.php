<?php

declare(strict_types=1);

namespace CodeVault\Reports;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Ai\AiProvider;
use CodeVault\Auth\AuthGuard;
use CodeVault\Billing\ExpiringReminderJob;
use CodeVault\Queue\QueueInterface;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Session\SessionManager;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\Support\App;
use CodeVault\View;

/**
 * Admin page for emailing clients whose services/domains renew within the
 * next 7 days — reached from the AI Insights dashboard widget.
 *
 * The admin sees the exact accounts due, can draft (or AI-generate) a
 * promotional reminder message, and send it to every affected client. Sending
 * is deferred to ExpiringReminderJob so the browser never blocks on a batch
 * of emails; the job reports a per-client summary to every admin.
 */
final class ExpiringReminderController
{
    private const SESSION_MESSAGE_KEY = 'expiring_reminder_message';
    private const SESSION_ERROR_KEY = 'expiring_reminder_error';
    private const DEFAULT_MESSAGE = 'Your hosting account with us is renewing soon. Renew on time to keep your services and domains running without interruption — thank you for choosing us!';

    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly ExpiringReminderService $expiring,
        private readonly AiProvider $ai,
        private readonly ActivityLogger $activity,
        private readonly SessionManager $session
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $accounts = $this->expiring->accountsExpiringSoon();

        $message = $this->session->get(self::SESSION_MESSAGE_KEY, self::DEFAULT_MESSAGE);
        $error = $this->session->get(self::SESSION_ERROR_KEY);
        $this->session->set(self::SESSION_ERROR_KEY, null);

        $serviceCount = 0;
        $domainCount = 0;
        foreach ($accounts as $account) {
            foreach ($account['items'] as $item) {
                if ($item['kind'] === 'domain') {
                    $domainCount++;
                } else {
                    $serviceCount++;
                }
            }
        }

        $content = $this->view->render('reports.expiring-reminders', [
            'accounts' => $accounts,
            'clientCount' => count($accounts),
            'serviceCount' => $serviceCount,
            'domainCount' => $domainCount,
            'message' => (string) $message,
            'error' => $error !== null && $error !== '' ? (string) $error : null,
        ]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'Expiring Account Reminders',
            'content' => $content,
        ]));
    }

    /**
     * "✨ Generate with AI" — asks the configured AI provider to draft a
     * promotional reminder message, then stores it in the session so the
     * page reloads with the generated text in the composer. The per-client
     * service/domain names, dates and amounts are always filled in from
     * real data at send time, so the AI only writes the promotional copy.
     */
    public function generate(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $accounts = $this->expiring->accountsExpiringSoon();

        $systemPrompt = 'You are the marketing assistant for a web hosting company. '
            . 'Write a short, friendly promotional reminder for clients whose services or domains renew within the next 7 days. '
            . 'Keep it to 3-5 sentences, warm and professional, encouraging on-time renewal and reinforcing reliable service. '
            . 'Do NOT use markdown, headings, or bullet lists — just a plain paragraph. '
            . 'Do not mention specific names or dates: the recipient\'s own service/domain names, due dates and amounts are added automatically to each email.';

        $itemsSummary = $this->describeItems($accounts);

        $result = $this->ai->complete($systemPrompt, $itemsSummary);

        if (!$result['success']) {
            $this->session->set(self::SESSION_ERROR_KEY, 'Could not generate a message: ' . (string) ($result['error'] ?? 'AI unavailable.'));
            $this->session->set(self::SESSION_MESSAGE_KEY, self::DEFAULT_MESSAGE);

            return Response::redirect('/admin/expiring-reminders');
        }

        $this->session->set(self::SESSION_MESSAGE_KEY, trim((string) $result['text']));

        $this->activity->log(
            'admin',
            (int) $this->guard->currentAdmin()['id'],
            'expiring_reminder_generated',
            'system',
            null,
            'Generated an expiring-account reminder message with the AI assistant.',
            $request->ip()
        );

        return Response::redirect('/admin/expiring-reminders');
    }

    /**
     * "📤 Send to all clients" — dispatches the batch send to the background
     * queue so the admin's browser returns immediately; ExpiringReminderJob
     * emails each client and reports the summary to every admin.
     */
    public function send(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $message = trim((string) $request->input('message', ''));

        if ($message === '') {
            return Response::redirect('/admin/expiring-reminders?error=' . urlencode('Write a message first, or click "Generate with AI".'));
        }

        $admin = $this->guard->currentAdmin();

        App::container()
            ->make(QueueInterface::class)
            ->push(new ExpiringReminderJob($message, (int) $admin['id'], $request->ip()));

        return Response::redirect('/admin/expiring-reminders?msg=' . urlencode('Reminder emails are sending in the background — you\'ll get a summary email when they finish.'));
    }

    /**
     * @param array<int, array{client_id: int, items: array<int, array{kind: string, name: string, domain: string, due_date: string, amount: string}>}> $accounts
     */
    private function describeItems(array $accounts): string
    {
        $serviceNames = [];
        $domainNames = [];
        $amounts = [];

        foreach ($accounts as $account) {
            foreach ($account['items'] as $item) {
                if ($item['kind'] === 'domain') {
                    $domainNames[] = $item['name'];
                } else {
                    $serviceNames[] = $item['name'];
                }
                if ($item['amount'] !== '') {
                    $amounts[] = $item['amount'];
                }
            }
        }

        return implode("\n", [
            'Total clients with accounts renewing in 7 days: ' . count($accounts),
            'Services renewing: ' . count($serviceNames) . ' (' . implode(', ', array_slice(array_unique($serviceNames), 0, 8)) . ')',
            'Domains renewing: ' . count($domainNames) . ' (' . implode(', ', array_slice(array_unique($domainNames), 0, 8)) . ')',
            'Renewal amounts seen: ' . implode(', ', array_slice(array_unique($amounts), 0, 6)),
        ]);
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::REPORTS_MANAGE)) {
            return Response::html('403 Forbidden — missing reports.manage permission', 403);
        }

        return null;
    }
}
