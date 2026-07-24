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
        private readonly \CodeVault\Clients\ClientRepository $clients
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
        if ($targetType === 'group' && (string) $request->input('client_group_id', '') !== '') {
            $groupId = (int) $request->input('client_group_id');
        } elseif ($targetType === 'individual' && (string) $request->input('client_id', '') !== '') {
            $clientId = (int) $request->input('client_id');
        }

        if ($subject === '' || $body === '') {
            return $this->render('marketing.campaigns-index', [
                'campaigns' => $this->campaigns->all(),
                'groups' => $this->groups->all(),
                'clients' => $this->clients->all(),
                'error' => 'Subject and body are required.'
            ]);
        }

        $this->campaigns->create($subject, $body, $groupId, $clientId);

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

        return $this->render('marketing.campaign-show', ['campaign' => $campaign, 'recipients' => $this->campaigns->recipients((int) $campaign['id'])]);
    }

    public function send(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->service->send((int) $params['id']);

        return Response::redirect("/admin/campaigns/{$params['id']}");
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
