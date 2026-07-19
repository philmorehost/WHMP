<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class NetworkIssueController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly NetworkIssueRepository $issues
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('support.network-issues-index', ['issues' => $this->issues->all()]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $title = trim((string) $request->input('title', ''));
        $message = trim((string) $request->input('message', ''));

        if ($title !== '' && $message !== '') {
            $this->issues->create($title, $message, 'investigating');
        }

        return Response::redirect('/admin/network-issues');
    }

    public function updateStatus(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $status = (string) $request->input('status', 'investigating');
        $message = trim((string) $request->input('message', ''));

        $this->issues->updateStatus($id, $status, $message !== '' ? $message : null);

        return Response::redirect('/admin/network-issues');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->issues->delete((int) $params['id']);

        return Response::redirect('/admin/network-issues');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::ANNOUNCEMENTS_MANAGE)) {
            return Response::html('403 Forbidden — missing announcements.manage permission', 403);
        }

        return null;
    }

    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Network Issues',
            'content' => $content,
        ]));
    }
}
