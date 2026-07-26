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
        $faviconUrl = trim((string) $request->input('favicon_url', ''));
        $primaryColor = trim((string) $request->input('primary_color', ''));
        $termsUrl = trim((string) $request->input('terms_url', ''));

        if (!$this->theme->isValidHex($primaryColor)) {
            return $this->render(['theme' => $this->theme->get(), 'error' => 'Primary color must be a hex code like #2f6fed.', 'saved' => false]);
        }

        if ($termsUrl !== '' && !filter_var($termsUrl, FILTER_VALIDATE_URL)) {
            return $this->render(['theme' => $this->theme->get(), 'error' => 'Terms of Service URL must be a full URL like https://yourdomain.com/terms.', 'saved' => false]);
        }

        // Handle uploaded favicon file if provided
        $file = $request->file('favicon_file');
        if ($file !== null && ($file['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            $allowed = ['ico', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'];

            if (!in_array($ext, $allowed, true)) {
                return $this->render(['theme' => $this->theme->get(), 'error' => 'Invalid favicon file type. Allowed formats: .ico, .png, .jpg, .svg, .webp', 'saved' => false]);
            }

            $uploadsDir = dirname(__DIR__, 2) . '/public/uploads';
            if (!is_dir($uploadsDir)) {
                @mkdir($uploadsDir, 0755, true);
            }

            $filename = 'favicon_' . time() . '.' . $ext;
            $destination = $uploadsDir . '/' . $filename;

            if (move_uploaded_file((string) $file['tmp_name'], $destination)) {
                $faviconUrl = '/uploads/' . $filename;
            }
        }

        $this->theme->save(
            $brandName,
            $logoUrl !== '' ? $logoUrl : null,
            $primaryColor,
            $termsUrl !== '' ? $termsUrl : null,
            $faviconUrl !== '' ? $faviconUrl : null
        );

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
