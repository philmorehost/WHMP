<?php

declare(strict_types=1);

namespace CodeVault\Theme;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class ThemeController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly ThemeSettings $theme
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render(['theme' => $this->theme->get(), 'error' => null, 'saved' => false]);
    }

    public function update(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $brandName = trim((string) $request->input('brand_name', ''));
        $logoUrl = trim((string) $request->input('logo_url', ''));
        $primaryColor = trim((string) $request->input('primary_color', ''));
        $termsUrl = trim((string) $request->input('terms_url', ''));

        if (!$this->theme->isValidHex($primaryColor)) {
            return $this->render(['theme' => $this->theme->get(), 'error' => 'Primary color must be a hex code like #2f6fed.', 'saved' => false]);
        }

        if ($termsUrl !== '' && !filter_var($termsUrl, FILTER_VALIDATE_URL)) {
            return $this->render(['theme' => $this->theme->get(), 'error' => 'Terms of Service URL must be a full URL like https://yourdomain.com/terms.', 'saved' => false]);
        }

        $this->theme->save($brandName, $logoUrl !== '' ? $logoUrl : null, $primaryColor, $termsUrl !== '' ? $termsUrl : null);

        return $this->render(['theme' => $this->theme->get(), 'error' => null, 'saved' => true]);
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
        $content = $this->view->render('theme.index', $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Theme',
            'content' => $content,
        ]));
    }
}
