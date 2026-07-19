<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class CannedReplyController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly CannedReplyRepository $cannedReplies
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $content = $this->view->render('support.canned-replies-index', ['cannedReplies' => $this->cannedReplies->all()]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Canned Replies',
            'content' => $content,
        ]));
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $title = trim((string) $request->input('title', ''));
        $body = trim((string) $request->input('body', ''));

        if ($title !== '' && $body !== '') {
            $this->cannedReplies->create($title, $body);
        }

        return Response::redirect('/admin/canned-replies');
    }

    public function update(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $title = trim((string) $request->input('title', ''));
        $body = trim((string) $request->input('body', ''));

        if ($title !== '' && $body !== '') {
            $this->cannedReplies->update($id, $title, $body);
        }

        return Response::redirect('/admin/canned-replies');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->cannedReplies->delete((int) $params['id']);

        return Response::redirect('/admin/canned-replies');
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
