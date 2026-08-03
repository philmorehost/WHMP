<?php

declare(strict_types=1);

namespace CodeVault\System;

use CodeVault\Auth\AuthGuard;
use CodeVault\Cron\CronRunRepository;
use CodeVault\Kernel;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Staff\PermissionRegistry;
use CodeVault\View;
use DateTimeImmutable;

/**
 * Shows the admin the exact cron command to install (with the real absolute
 * path on this server) plus copy buttons and step-by-step control-panel
 * instructions. WHMP's automation (recurring billing, dunning, auto-charge,
 * domain sync, ticket escalation, backups, ...) only runs once this single
 * entry point is wired to the server's scheduler.
 */
final class CronInfoController
{
    /** @var array<int, array{name: string, desc: string}> */
    private const JOBS = [
        ['name' => 'Recurring billing', 'desc' => 'Generates renewal invoices before the due date'],
        ['name' => 'Auto-charge', 'desc' => 'Charges saved cards for due invoices, before dunning'],
        ['name' => 'Dunning', 'desc' => 'Emails reminders for overdue invoices'],
        ['name' => 'Renewal reminders', 'desc' => 'Pre-renewal reminder emails'],
        ['name' => 'Domain renewal billing', 'desc' => 'Invoices domain renewals'],
        ['name' => 'Domain sync', 'desc' => 'Syncs domain status from registrars'],
        ['name' => 'Billable item invoicing', 'desc' => 'Rolls ad-hoc charges into invoices'],
        ['name' => 'Quote expiry', 'desc' => 'Expires stale quotes'],
        ['name' => 'Ticket escalation', 'desc' => 'Escalates SLA-breaching tickets'],
        ['name' => 'Ticket auto-close', 'desc' => 'Closes idle resolved tickets'],
        ['name' => 'Mail piping', 'desc' => 'Turns inbound email into tickets/replies'],
        ['name' => 'Backups', 'desc' => 'Scheduled database backups'],
        ['name' => 'Data pruning', 'desc' => 'GDPR-driven data retention pruning'],
        ['name' => 'Integrity check', 'desc' => 'Scheduled integrity/licensing checks'],
        ['name' => 'Email queue', 'desc' => 'Processes queued outbound email'],
    ];

    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly Kernel $kernel,
        private readonly SettingsRepository $settings,
        private readonly CronRunRepository $runs
    ) {
    }

    /**
     * Automation schedule + daily report settings. WHMCS-style: one daily run
     * time governs every daily job, rather than a per-job schedule.
     */
    public function update(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $time = trim((string) $request->input('daily_run_time', ''));

        // Reject anything that isn't HH:MM rather than storing a value the
        // scheduler would silently ignore, which would look like the setting
        // simply didn't take.
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time) === 1) {
            $this->settings->set('automation.daily_run_time', $time);
        }

        $this->settings->set('automation.report_enabled', $request->input('report_enabled') !== null ? '1' : '0');

        $email = trim((string) $request->input('report_email', ''));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $this->settings->set('automation.report_email', $email);
        }

        return Response::redirect('/admin/cron?saved=1');
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $cronPath = $this->kernel->basePath('bin/cron.php');
        $logPath = $this->kernel->basePath('storage/cron.log');

        // Primary: run every minute (each job self-checks whether it's due, so
        // this is safe and matches the entry point's own recommendation).
        $everyMinute = "* * * * * php \"{$cronPath}\" >> \"{$logPath}\" 2>&1";
        // Alternative for hosts that cap cron frequency at 5 minutes.
        $everyFiveMinutes = "*/5 * * * * php \"{$cronPath}\" >> \"{$logPath}\" 2>&1";

        $recentRuns = $this->runs->since((new DateTimeImmutable())->modify('-24 hours'));

        $content = $this->view->render('system.cron', [
            'cronPath' => $cronPath,
            'logPath' => $logPath,
            'everyMinute' => $everyMinute,
            'everyFiveMinutes' => $everyFiveMinutes,
            'jobs' => self::JOBS,
            'dailyRunTime' => (string) ($this->settings->get('automation.daily_run_time', '00:05') ?: '00:05'),
            'reportEnabled' => $this->settings->get('automation.report_enabled', '1') === '1',
            'reportEmail' => (string) ($this->settings->get('automation.report_email', '') ?? ''),
            'lastReportDate' => (string) ($this->settings->get('automation.last_report_date', '') ?? ''),
            'recentStats' => $this->runs->sumStats($recentRuns),
            'recentErrors' => $this->runs->errors($recentRuns),
            'recentRunCount' => count($recentRuns),
            'saved' => $request->query('saved') === '1',
        ]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Cron & Automation',
            'content' => $content,
        ]));
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
