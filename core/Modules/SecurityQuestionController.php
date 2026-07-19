<?php

declare(strict_types=1);

namespace CodeVault\Modules;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class SecurityQuestionController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly SecurityQuestionModuleService $questions,
        private readonly ActivityLogger $activity
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $content = $this->view->render('security-questions.index', ['questions' => $this->questions->catalog()]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Security Questions',
            'content' => $content,
        ]));
    }

    public function activate(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $slug = (string) $params['slug'];
        $result = $this->questions->activate($slug);

        if ($result['success']) {
            $adminId = (int) $this->guard->currentAdmin()['id'];
            $this->activity->log('admin', $adminId, 'security_question.activated', 'security_question', 0, "Activated security question [{$slug}]", $request->ip());
        }

        return Response::redirect('/admin/security-questions');
    }

    public function deactivate(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $slug = (string) $params['slug'];
        $result = $this->questions->deactivate($slug);

        if ($result['success']) {
            $adminId = (int) $this->guard->currentAdmin()['id'];
            $this->activity->log('admin', $adminId, 'security_question.deactivated', 'security_question', 0, "Deactivated security question [{$slug}]", $request->ip());
        }

        return Response::redirect('/admin/security-questions');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::SECURITY_QUESTIONS_MANAGE)) {
            return Response::html('403 Forbidden — missing security_questions.manage permission', 403);
        }

        return null;
    }
}
