<?php

declare(strict_types=1);

namespace CodeVault\Localization;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class LanguageController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly LanguageRepository $languages
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render(['languages' => $this->languages->all()]);
    }

    public function toggleActive(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $language = $this->languages->find((int) $params['id']);

        if ($language !== null && (int) $language['is_default'] !== 1) {
            $this->languages->setActive((int) $language['id'], (int) $language['is_active'] !== 1);
        }

        return Response::redirect('/admin/languages');
    }

    public function setDefault(Request $request, array $params): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->languages->setDefault((int) $params['id']);

        return Response::redirect('/admin/languages');
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
        $content = $this->view->render('localization.languages-index', $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Languages',
            'content' => $content,
        ]));
    }
}
