<?php

declare(strict_types=1);

namespace CodeVault\Modules;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class AddonController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly AddonModuleService $addons,
        private readonly ActivityLogger $activity
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render('addons.index', ['addons' => $this->addons->catalog()]);
    }

    public function activate(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $slug = (string) $params['slug'];
        $result = $this->addons->activate($slug);

        if ($result['success']) {
            $adminId = (int) $this->guard->currentAdmin()['id'];
            $this->activity->log('admin', $adminId, 'addon.activated', 'addon', 0, "Activated addon [{$slug}]", $request->ip());
        }

        return Response::redirect('/admin/addons');
    }

    public function deactivate(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $slug = (string) $params['slug'];
        $result = $this->addons->deactivate($slug);

        if ($result['success']) {
            $adminId = (int) $this->guard->currentAdmin()['id'];
            $this->activity->log('admin', $adminId, 'addon.deactivated', 'addon', 0, "Deactivated addon [{$slug}]", $request->ip());
        }

        return Response::redirect('/admin/addons');
    }

    public function show(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $slug = (string) $params['slug'];
        $module = $this->addons->find($slug);

        if ($module === null) {
            return Response::html('404 Not Found — unknown addon', 404);
        }

        $renderParams = (array) $request->input();
        // Addons render their own settings forms, so they need the CSRF
        // field for any POST they emit. Handed in rather than leaving the
        // addon to reach for the container, which keeps render() unit-testable.
        $renderParams['csrf_field'] = csrf_field();

        return $this->render('addons.show', [
            'slug' => $slug,
            'metadata' => $module->metadata(),
            'output' => $module->render($renderParams),
        ]);
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::ADDONS_MANAGE)) {
            return Response::html('403 Forbidden — missing addons.manage permission', 403);
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function render(string $view, array $data): Response
    {
        $content = $this->view->render($view, $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Addons',
            'content' => $content,
        ]));
    }
}
