<?php

declare(strict_types=1);

namespace CodeVault\Notifications;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

/**
 * Admin side of the in-app client notification center — compose a message
 * and send it to one client, a hand-picked set, or every active client.
 * Every notification also lands in email_log-mirrored form automatically
 * (see EmailDispatcher) whenever the system emails a client directly; this
 * controller is only for notifications an admin writes by hand.
 */
final class ClientNotificationController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly ClientNotificationRepository $notifications,
        private readonly ClientRepository $clients,
        private readonly ActivityLogger $activity
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render([
            'sent' => $this->notifications->allForAdmin(50),
            'clients' => $this->clients->all(),
            'error' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $subject = trim((string) $request->input('subject', ''));
        $body = trim((string) $request->input('body', ''));
        $target = (string) $request->input('target', 'individual');

        if ($subject === '' || $body === '') {
            return $this->render([
                'sent' => $this->notifications->allForAdmin(50),
                'clients' => $this->clients->all(),
                'error' => 'Subject and message are both required.',
            ]);
        }

        $clientIds = match ($target) {
            'all' => array_map(static fn (array $c): int => (int) $c['id'], $this->clients->activeForGroup(null)),
            'selected' => array_map('intval', (array) $request->input('client_ids', [])),
            default => array_filter([(int) $request->input('client_id', 0)]),
        };

        $clientIds = array_values(array_unique(array_filter($clientIds, static fn ($id): bool => (int) $id > 0)));

        if ($clientIds === []) {
            return $this->render([
                'sent' => $this->notifications->allForAdmin(50),
                'clients' => $this->clients->all(),
                'error' => 'Pick at least one recipient.',
            ]);
        }

        $admin = $this->guard->currentAdmin();
        $notificationId = $this->notifications->send($subject, $body, $clientIds, 'admin', null, (int) $admin['id']);

        $this->activity->log(
            'admin',
            (int) $admin['id'],
            'client_notification.sent',
            'notification',
            $notificationId,
            "Sent notification \"{$subject}\" to " . count($clientIds) . ' client(s)',
            $request->ip()
        );

        return Response::redirect('/admin/client-notifications?sent=1');
    }

    public function show(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $notification = $this->notifications->find((int) $params['id']);

        if ($notification === null) {
            return Response::html('404 Not Found', 404);
        }

        return $this->render([
            'notification' => $notification,
            'recipients' => $this->notifications->recipientsFor((int) $notification['id']),
            'sent' => [],
            'clients' => [],
            'error' => null,
            'detail' => true,
        ]);
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::CLIENT_NOTIFICATIONS_MANAGE)) {
            return Response::html('403 Forbidden — missing client_notifications.manage permission', 403);
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function render(array $data): Response
    {
        $template = !empty($data['detail']) ? 'notifications.client-notification-show' : 'notifications.client-notifications-index';
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Client Notifications',
            'content' => $content,
        ]));
    }
}
