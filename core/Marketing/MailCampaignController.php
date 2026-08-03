<?php

declare(strict_types=1);

namespace CodeVault\Marketing;

use CodeVault\Auth\AuthGuard;
use CodeVault\Clients\ClientGroupRepository;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class MailCampaignController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly MailCampaignRepository $campaigns,
        private readonly ClientGroupRepository $groups,
        private readonly MailCampaignService $service,
        private readonly \CodeVault\Clients\ClientRepository $clients,
        private readonly \CodeVault\Settings\SettingsRepository $settings
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('marketing.campaigns-index', [
            'campaigns' => $this->campaigns->all(),
            'groups' => $this->groups->all(),
            'clients' => $this->clients->all(),
            'error' => null
        ]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $subject = trim((string) $request->input('subject', ''));
        $body = trim((string) $request->input('body', ''));
        $targetType = (string) $request->input('target_type', 'all');

        $groupId = null;
        $clientId = null;
        $externalEmails = null;
        if ($targetType === 'group' && (string) $request->input('client_group_id', '') !== '') {
            $groupId = (int) $request->input('client_group_id');
        } elseif ($targetType === 'individual' && (string) $request->input('client_id', '') !== '') {
            $clientId = (int) $request->input('client_id');
        } elseif ($targetType === 'external') {
            // Normalise at save time so the stored list is exactly what will be
            // sent — the admin can see on the campaign what it resolved to,
            // rather than discovering typos only after sending.
            $parsed = MailCampaignService::parseExternalEmails((string) $request->input('external_emails', ''));

            if ($parsed === []) {
                return $this->render('marketing.campaigns-index', [
                    'campaigns' => $this->campaigns->all(),
                    'groups' => $this->groups->all(),
                    'clients' => $this->clients->all(),
                    'error' => 'Enter at least one valid email address for an external campaign.'
                ]);
            }

            $externalEmails = implode("\n", $parsed);
        }

        if ($subject === '' || $body === '') {
            return $this->render('marketing.campaigns-index', [
                'campaigns' => $this->campaigns->all(),
                'groups' => $this->groups->all(),
                'clients' => $this->clients->all(),
                'error' => 'Subject and body are required.'
            ]);
        }

        $this->campaigns->create($subject, $body, $groupId, $clientId, $externalEmails);

        return Response::redirect('/admin/campaigns');
    }

    public function show(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $campaign = $this->campaigns->find((int) $params['id']);

        if ($campaign === null) {
            return Response::html('404 Not Found', 404);
        }

        return $this->render('marketing.campaign-show', [
            'campaign' => $campaign,
            'recipients' => $this->campaigns->recipients((int) $campaign['id']),
            // Shown in the preview's header bar so it mirrors the real email.
            'brandName' => trim((string) ($this->settings->get('theme.brand_name', '') ?? '')) ?: 'Email preview',
            'queuedCount' => $request->query('queued') !== null ? max(0, (int) $request->query('queued')) : null,
        ]);
    }

    public function send(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        // Queue only — the cron sends in throttled batches, so this returns
        // immediately regardless of audience size.
        $queued = $this->service->queue((int) $params['id']);

        return Response::redirect("/admin/campaigns/{$params['id']}?queued={$queued}");
    }

    /**
     * Stops a sending campaign so it can be reviewed — the cron's own filter
     * on `status = 'sending'` does the actual work; this just flips the flag.
     */
    public function pause(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $paused = $this->service->pause($id);

        return Response::redirect("/admin/campaigns/{$id}?" . ($paused ? 'paused=1' : 'pause_error=1'));
    }

    /** Puts a paused campaign back in front of the cron, with whatever edits were made while it was stopped. */
    public function resume(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $resumed = $this->service->resume($id);

        return Response::redirect("/admin/campaigns/{$id}?" . ($resumed ? 'resumed=1' : 'resume_error=1'));
    }

    /**
     * Edits a draft or paused campaign's subject/body — the "review, edit"
     * step of pause → review → edit → resend. See
     * MailCampaignRepository::update() for why 'sending' itself is excluded.
     */
    public function update(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $subject = trim((string) $request->input('subject', ''));
        $body = trim((string) $request->input('body', ''));

        if ($subject === '' || $body === '') {
            return Response::redirect("/admin/campaigns/{$id}?edit_error=" . urlencode('Subject and body are required.'));
        }

        $updated = $this->service->update($id, $subject, $body);

        return Response::redirect("/admin/campaigns/{$id}?" . ($updated ? 'updated=1' : 'edit_error=' . urlencode('This campaign cannot be edited right now — pause it first if it is sending, or it may already have been sent.')));
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::SETTINGS_MANAGE)) {
            return Response::html('403 Forbidden — missing settings.manage permission', 403);
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Campaigns',
            'content' => $content,
        ]));
    }
}
