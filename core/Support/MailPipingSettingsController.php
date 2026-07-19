<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class MailPipingSettingsController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly SettingsRepository $settings
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render([
            'enabled' => $this->settings->get('mail_piping.enabled', '0') === '1',
            'host' => $this->settings->get('mail_piping.host', ''),
            'port' => $this->settings->get('mail_piping.port', '993'),
            'encryption' => $this->settings->get('mail_piping.encryption', 'ssl'),
            'username' => $this->settings->get('mail_piping.username', ''),
        ]);
    }

    public function store(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $this->settings->set('mail_piping.enabled', (string) $request->input('enabled', '') === '1' ? '1' : '0');
        $this->settings->set('mail_piping.host', trim((string) $request->input('host', '')));
        $this->settings->set('mail_piping.port', trim((string) $request->input('port', '993')));
        $this->settings->set('mail_piping.encryption', trim((string) $request->input('encryption', 'ssl')));
        $this->settings->set('mail_piping.username', trim((string) $request->input('username', '')));

        $password = (string) $request->input('password', '');

        if ($password !== '') {
            $this->settings->set('mail_piping.password', $password);
        }

        return Response::redirect('/admin/mail-piping');
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
        $content = $this->view->render('support.mail-piping-settings', $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Mail Piping',
            'content' => $content,
        ]));
    }
}
