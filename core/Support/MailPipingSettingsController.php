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
        private readonly SettingsRepository $settings,
        private readonly MailboxClient $mailbox
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
            'validate_cert' => $this->settings->get('mail_piping.validate_cert', '0') === '1',
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
        $this->settings->set('mail_piping.validate_cert', (string) $request->input('validate_cert', '') === '1' ? '1' : '0');

        $password = (string) $request->input('password', '');

        if ($password !== '') {
            $this->settings->set('mail_piping.password', $password);
        }

        return Response::redirect('/admin/mail-piping');
    }

    /**
     * AJAX endpoint for the "Test connection" button. Uses the currently
     * SAVED settings so the admin can validate what the cron job will
     * actually use (password included, since it is stored on save).
     */
    public function test(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $config = [
            'host' => (string) $this->settings->get('mail_piping.host', ''),
            'port' => (int) $this->settings->get('mail_piping.port', '993'),
            'encryption' => (string) $this->settings->get('mail_piping.encryption', 'ssl'),
            'username' => (string) $this->settings->get('mail_piping.username', ''),
            'password' => (string) $this->settings->get('mail_piping.password', ''),
            'validate_cert' => $this->settings->get('mail_piping.validate_cert', '0') === '1',
        ];

        if ($config['host'] === '' || $config['username'] === '' || $config['password'] === '') {
            return Response::json([
                'success' => false,
                'message' => 'Save the mailbox details first — host, username and password are all required.',
            ], 422);
        }

        return Response::json($this->mailbox->testConnection($config));
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
