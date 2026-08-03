<?php

declare(strict_types=1);

namespace CodeVault\Cron;

use CodeVault\Config;
use CodeVault\Database;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Settings\SettingsRepository;
use DateTimeImmutable;
use Throwable;

/**
 * The WHMCS-style daily automation digest: one email to the admin summarising
 * what every cron job did in the last 24 hours, plus anything that failed.
 *
 * Counters come from two places, deliberately:
 *  - Jobs that implement ReportsCronStats report their own totals, recorded
 *    per run in `cron_job_runs` and summed over the window here.
 *  - A few numbers are derived straight from the data (emails actually sent),
 *    because no single job owns them.
 * Anything neither instrumented nor derivable reads 0, exactly as WHMCS shows
 * 0 for a task with nothing to do.
 *
 * Registered like any other CronJob, so it runs on the same single OS cron
 * entry and honours the admin's configured automation time.
 */
final class CronActivityReportJob implements CronJob
{
    public function __construct(
        private readonly CronRunRepository $runs,
        private readonly SettingsRepository $settings,
        private readonly EmailDispatcher $mail,
        private readonly Database $db,
        private readonly Config $config
    ) {
    }

    public function name(): string
    {
        return 'cron_activity_report';
    }

    public function frequencyMinutes(): int
    {
        return 1440;
    }

    public function handle(): void
    {
        if ($this->settings->get('automation.report_enabled', '1') !== '1') {
            return;
        }

        $recipient = $this->resolveRecipient();

        if ($recipient === null) {
            return;
        }

        $now = new DateTimeImmutable();
        $windowStart = $now->modify('-24 hours');

        // Guard against a double send if the cron ticks again inside the same
        // day (e.g. the state file is cleared, or the job is run by hand).
        if ($this->settings->get('automation.last_report_date', '') === $now->format('Y-m-d')) {
            return;
        }

        $runs = $this->runs->since($windowStart);
        $totals = $this->runs->sumStats($runs);
        $errors = $this->runs->errors($runs);

        $executed = 0;

        foreach ($runs as $run) {
            if (($run['status'] ?? '') === 'success') {
                $executed++;
            }
        }

        $counter = static fn (string $key): string => number_format((float) ($totals[$key] ?? 0), 0);

        $variables = [
            'report_date' => $now->format('F j, Y'),
            'generated_at' => $now->format('H:i'),
            'company_name' => (string) ($this->settings->get('theme.brand_name', 'CodeVault') ?: 'CodeVault'),
            'admin_url' => rtrim((string) $this->config->env('APP_URL', ''), '/') . '/admin',
            'status_banner' => $this->statusBanner($errors),
            'job_errors_section' => $this->errorsSection($errors),
            'invoices_generated' => $counter('invoices_generated'),
            'late_fees_added' => $counter('late_fees_added'),
            'credit_card_charges' => $counter('credit_card_charges'),
            'overdue_reminders' => $counter('overdue_reminders'),
            'overdue_suspensions' => $counter('overdue_suspensions'),
            'domain_renewals' => $counter('domain_renewals'),
            'renewal_reminders' => $counter('renewal_reminders'),
            'tickets_escalated' => $counter('tickets_escalated'),
            'tickets_auto_closed' => $counter('tickets_auto_closed'),
            'cancellations' => $counter('cancellations'),
            'services_terminated' => $counter('services_terminated'),
            'services_pruned' => $counter('services_pruned'),
            'invoices_auto_cancelled' => $counter('invoices_auto_cancelled'),
            'domains_pruned' => $counter('domains_pruned'),
            'emails_sent' => number_format((float) $this->emailsSentSince($windowStart), 0),
            'jobs_executed' => number_format((float) $executed, 0),
        ];

        $this->mail->sendTemplate('cron_activity_report', $recipient, $variables);

        $this->settings->set('automation.last_report_date', $now->format('Y-m-d'));

        $this->runs->pruneOlderThan($now->modify('-' . $this->runLogRetentionDays() . ' days'));
    }

    /** Days of cron_job_runs history kept — admin-configurable, defaults to 30. */
    private function runLogRetentionDays(): int
    {
        $value = $this->settings->get('automation.run_log_retention_days', '30');

        if ($value === null || trim((string) $value) === '' || !is_numeric($value)) {
            return 30;
        }

        return max(1, (int) $value);
    }

    /**
     * Explicit setting first, then the first admin account — an admin who
     * never opens Automation Settings still gets the report.
     */
    private function resolveRecipient(): ?string
    {
        $configured = trim((string) ($this->settings->get('automation.report_email', '') ?? ''));

        if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_EMAIL) !== false) {
            return $configured;
        }

        try {
            $admin = $this->db->selectOne('SELECT email FROM admins ORDER BY id ASC LIMIT 1');
        } catch (Throwable) {
            return null;
        }

        $email = trim((string) ($admin['email'] ?? ''));

        return $email !== '' ? $email : null;
    }

    private function emailsSentSince(DateTimeImmutable $since): int
    {
        try {
            $row = $this->db->selectOne(
                "SELECT COUNT(*) AS c FROM email_log WHERE status = 'sent' AND created_at >= ?",
                [$since->format('Y-m-d H:i:s')]
            );

            return (int) ($row['c'] ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /** @param array<int, array{job_name: string, error_message: string}> $errors */
    private function statusBanner(array $errors): string
    {
        if ($errors === []) {
            return '<div style="background:#e6f6ec;color:#1a7f45;border-radius:6px;padding:12px;text-align:center;font-weight:600;font-size:14px;margin-bottom:20px;">All cron automation tasks completed successfully</div>';
        }

        $count = count($errors);
        $label = $count === 1 ? '1 automation task reported an error' : "{$count} automation tasks reported errors";

        return '<div style="background:#fff3cd;color:#856404;border-radius:6px;padding:12px;text-align:center;font-weight:600;font-size:14px;margin-bottom:20px;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    /**
     * Pre-rendered because the template engine is a flat {{key}} strtr() with
     * no loop construct — see the note in migration 0128.
     *
     * @param array<int, array{job_name: string, error_message: string}> $errors
     */
    private function errorsSection(array $errors): string
    {
        if ($errors === []) {
            return '';
        }

        $items = '';

        foreach ($errors as $error) {
            $items .= '<li style="margin-bottom:6px;"><strong>'
                . htmlspecialchars($error['job_name'], ENT_QUOTES, 'UTF-8')
                . ':</strong> '
                . htmlspecialchars(mb_strimwidth($error['error_message'], 0, 300, '...'), ENT_QUOTES, 'UTF-8')
                . '</li>';
        }

        return '<div style="background:#fff3cd;border-left:4px solid #ffc107;border-radius:6px;padding:16px;margin-bottom:20px;">'
            . '<h3 style="color:#856404;margin:0 0 8px;font-size:15px;">Job Errors Detected</h3>'
            . '<ul style="color:#856404;font-size:13px;margin:0;padding-left:18px;">' . $items . '</ul>'
            . '</div>';
    }
}
