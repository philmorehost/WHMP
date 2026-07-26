<?php

declare(strict_types=1);

namespace CodeVault\Configuration;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;

final class CronSettingsController
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

        $content = $this->view->render('configuration.cron', [
            'reportEnabled' => $this->settings->get('cron.activity_report_enabled', 'true') === 'true',
            'reportTime' => $this->settings->get('cron.activity_report_time', '06:00'),
            'reportEmail' => $this->settings->get('cron.activity_report_email', ''),
            'saved' => $request->query('saved') === '1',
        ]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Cron & Automation Settings',
            'content' => $content,
        ]));
    }

    public function update(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        // Activity report enable/disable
        $this->settings->set('cron.activity_report_enabled', $request->input('activity_report_enabled') ? 'true' : 'false');

        // Report time — validate format HH:MM
        $reportTime = trim((string) $request->input('activity_report_time', '06:00'));
        if (preg_match('/^\d{2}:\d{2}$/', $reportTime)) {
            $this->settings->set('cron.activity_report_time', $reportTime);
        }

        // Report recipient email
        $reportEmail = trim((string) $request->input('activity_report_email', ''));
        if ($reportEmail && filter_var($reportEmail, FILTER_VALIDATE_EMAIL)) {
            $this->settings->set('cron.activity_report_email', $reportEmail);
        } elseif (!$reportEmail) {
            // Allow clearing the setting
            $this->settings->set('cron.activity_report_email', '');
        }

        return Response::redirect('/admin/settings/cron?saved=1');
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
}
