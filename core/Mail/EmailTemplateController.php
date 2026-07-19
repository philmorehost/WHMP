<?php

declare(strict_types=1);

namespace CodeVault\Mail;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class EmailTemplateController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly EmailTemplateRepository $templates,
        private readonly EmailLogRepository $log
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('email-templates.index', ['templates' => $this->templates->all()]);
    }

    public function editForm(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $template = $this->templates->find((int) $params['id']);

        if ($template === null) {
            return Response::html('404 Not Found', 404);
        }

        return $this->render('email-templates.form', ['template' => $template, 'error' => null]);
    }

    public function update(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $id = (int) $params['id'];
        $name = trim((string) $request->input('name', ''));
        $subject = trim((string) $request->input('subject', ''));
        $bodyHtml = (string) $request->input('body_html', '');

        if ($name === '' || $subject === '' || $bodyHtml === '') {
            $template = $this->templates->find($id);

            return $this->render('email-templates.form', ['template' => $template, 'error' => 'Name, subject, and body are all required.']);
        }

        $this->templates->update($id, $name, $subject, $bodyHtml);

        return Response::redirect('/admin/email-templates');
    }

    public function log(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('email-templates.log', ['entries' => $this->log->recent(50)]);
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::EMAIL_TEMPLATES_MANAGE)) {
            return Response::html('403 Forbidden — missing email_templates.manage permission', 403);
        }

        return null;
    }

    private function render(string $template, array $data): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Email Templates',
            'content' => $content,
        ]));
    }
}
