<?php

declare(strict_types=1);

namespace CodeVault\System;

use CodeVault\Cron\CronScheduler;
use CodeVault\Http\Request;
use CodeVault\Http\Response;
use CodeVault\Security\AuthGuard;
use CodeVault\Settings\SettingsRepository;
use CodeVault\View;

final class AutomationSettingsController
{
    public function __construct(
        private readonly CronScheduler $scheduler,
        private readonly SettingsRepository $settings,
        private readonly AuthGuard $auth,
        private readonly View $view
    ) {
    }

    public function index(Request $request): Response
    {
        $this->auth->requirePermission('SETTINGS_MANAGE');

        $jobs = [];
        foreach ($this->scheduler->jobs() as $job) {
            $name = $job->name();
            $jobs[] = [
                'name' => $name,
                'display_name' => $this->formatJobName($name),
                'frequency_minutes' => $job->frequencyMinutes(),
                'enabled' => $this->settings->get("automation.{$name}.enabled", 'true') === 'true',
                'time_of_day' => $this->settings->get("automation.{$name}.time_of_day", '02:00'),
            ];
        }

        $content = $this->view->render('system.automation_settings', [
            'jobs' => $jobs,
        ]);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'Automation Settings',
            'content' => $content,
        ]));
    }

    public function update(Request $request): Response
    {
        $this->auth->requirePermission('SETTINGS_MANAGE');

        $data = $request->post();

        foreach ($this->scheduler->jobs() as $job) {
            $name = $job->name();
            $enabled = isset($data["enabled_{$name}"]) ? 'true' : 'false';
            $timeOfDay = $data["time_of_day_{$name}"] ?? '02:00';

            $this->settings->set("automation.{$name}.enabled", $enabled);
            $this->settings->set("automation.{$name}.time_of_day", $timeOfDay);
        }

        return Response::redirect('/admin/system/automation?saved=1');
    }

    private function formatJobName(string $name): string
    {
        $map = [
            'cron-activity-report' => 'Daily Activity Report',
            'recurring-billing' => 'Recurring Billing',
            'auto-charge' => 'Auto Charge',
            'dunning' => 'Dunning Notices',
            'domain-renewal-billing' => 'Domain Renewal Billing',
            'domain-sync' => 'Domain Sync',
            'ticket-escalation' => 'Ticket Escalation',
            'ticket-auto-close' => 'Auto-Close Tickets',
            'billable-items' => 'Billable Item Invoicing',
            'mail-piping' => 'Mail Piping',
            'backup' => 'Backups',
            'cancellation' => 'Cancellations',
            'renewal-reminder' => 'Renewal Reminders',
            'data-pruning' => 'Data Pruning',
            'quote-expiry' => 'Quote Expiry',
            'integrity-check' => 'Integrity Check',
        ];

        return $map[$name] ?? ucwords(str_replace('-', ' ', $name));
    }
}
