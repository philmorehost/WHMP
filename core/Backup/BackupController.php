<?php

declare(strict_types=1);

namespace CodeVault\Backup;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class BackupController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly BackupRunRepository $runs,
        private readonly BackupService $backups
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render(['runs' => $this->runs->all()]);
    }

    public function trigger(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->backups->run();

        return Response::redirect('/admin/backups');
    }

    /**
     * "Terminate running backups" — closes out every stuck running entry and
     * deletes its partial files (see BackupService::terminateRunning()).
     * The outcome rides the redirect as query flags, same pattern as the
     * campaign delete notices, because the render has no session flash.
     */
    public function terminate(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $result = $this->backups->terminateRunning();

        $params = ['terminated' => $result['terminated'], 'files' => $result['files']];

        if ($result['live']) {
            $params['live'] = '1';
        }

        return Response::redirect('/admin/backups?' . http_build_query($params));
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
    private function render(array $data): Response
    {
        $content = $this->view->render('backup.index', $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Backups',
            'content' => $content,
        ]));
    }
}
