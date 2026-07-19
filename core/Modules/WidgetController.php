<?php

declare(strict_types=1);

namespace CodeVault\Modules;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class WidgetController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly WidgetModuleService $widgets,
        private readonly ActivityLogger $activity
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $content = $this->view->render('widgets.index', ['widgets' => $this->widgets->catalog()]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Widgets',
            'content' => $content,
        ]));
    }

    public function activate(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $slug = (string) $params['slug'];
        $result = $this->widgets->activate($slug);

        if ($result['success']) {
            $adminId = (int) $this->guard->currentAdmin()['id'];
            $this->activity->log('admin', $adminId, 'widget.activated', 'widget', 0, "Activated widget [{$slug}]", $request->ip());
        }

        return Response::redirect('/admin/widgets');
    }

    public function deactivate(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $slug = (string) $params['slug'];
        $result = $this->widgets->deactivate($slug);

        if ($result['success']) {
            $adminId = (int) $this->guard->currentAdmin()['id'];
            $this->activity->log('admin', $adminId, 'widget.deactivated', 'widget', 0, "Deactivated widget [{$slug}]", $request->ip());
        }

        return Response::redirect('/admin/widgets');
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::WIDGETS_MANAGE)) {
            return Response::html('403 Forbidden — missing widgets.manage permission', 403);
        }

        return null;
    }
}
