<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;
use DateTimeImmutable;

final class AnnouncementController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly AnnouncementRepository $announcements
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('support.announcements-index', ['announcements' => $this->announcements->all()]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $title = trim((string) $request->input('title', ''));
        $body = trim((string) $request->input('body', ''));
        $publishedAt = trim((string) $request->input('published_at', ''));

        if ($publishedAt === '') {
            $publishedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        } else {
            $publishedAt = str_contains($publishedAt, ':') ? str_replace('T', ' ', $publishedAt) . ':00' : $publishedAt;
        }

        if ($title !== '' && $body !== '') {
            $this->announcements->create($title, $body, $publishedAt);
        }

        return Response::redirect('/admin/announcements');
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->announcements->delete((int) $params['id']);

        return Response::redirect('/admin/announcements');
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
            'title' => 'CodeVault Admin — Announcements',
            'content' => $content,
        ]));
    }
}
