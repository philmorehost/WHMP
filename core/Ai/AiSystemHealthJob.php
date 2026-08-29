<?php

declare(strict_types=1);

namespace CodeVault\Ai;

use CodeVault\Config;
use CodeVault\Cron\CronJob;
use CodeVault\Cron\CronRunRepository;
use CodeVault\Database;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Settings\SettingsRepository;
use DateTimeImmutable;
use Throwable;

/**
 * Weekly AI-assisted system health scan — one CronJob on the single system
 * cron, every 7 days:
 *
 *   1. Collects what failed over the last week: `cron_job_runs` errors and the
 *      PHP error-log tail (storage/cache/php-error.log).
 *   2. Sends it to the AI provider (DeepSeek) to rank the failures, explain
 *      root causes, suggest fixes, and — when it spots a feature that would
 *      prevent or mitigate them — propose a concrete implementation plan.
 *   3. Emails the admin the raw error log plus the AI report / plan.
 *
 * Fails open by design: if the AI key is missing or the call errors, the admin
 * STILL gets the raw error log (the {{ai_error}} placeholder explains why the
 * analysis is absent) — the error log is never silently swallowed.
 */
final class AiSystemHealthJob implements CronJob
{
    private const WEEK_MINUTES = 10080;

    public function __construct(
        private readonly CronRunRepository $runs,
        private readonly SettingsRepository $settings,
        private readonly EmailDispatcher $mail,
        private readonly Database $db,
        private readonly Config $config,
        private readonly AiProvider $ai
    ) {
    }

    public function name(): string
    {
        return 'ai_system_health';
    }

    public function frequencyMinutes(): int
    {
        return self::WEEK_MINUTES;
    }

    public function handle(): void
    {
        if ($this->settings->get('automation.ai_health_enabled', '1') !== '1') {
            return;
        }

        $recipient = $this->resolveRecipient();

        if ($recipient === null) {
            return;
        }

        $now = new DateTimeImmutable();

        // Guard against a double send if the cron ticks again inside the same
        // week (state cleared, or the job is run by hand).
        if ($this->settings->get('automation.ai_health_last_run', '') === $now->format('Y-m-d')) {
            return;
        }

        $weekAgo = $now->modify('-7 days');
        $cronErrors = $this->runs->errors($this->runs->since($weekAgo));
        $phpErrors = $this->phpErrorTail();

        $context = PiiRedactor::redact($this->buildContext($cronErrors, $phpErrors));
        $aiResult = $this->ai->complete(
            'You are the senior systems administrator of a web-hosting platform. '
            . 'A weekly automated scan collected the errors below from the last 7 days. '
            . 'Analyse them: identify root causes, rank by severity, and recommend concrete fixes. '
            . 'Then — separately — if any feature enhancement to the platform would prevent or mitigate '
            . 'these issues (or clearly improve operations), propose a concrete, prioritised implementation plan '
            . 'with steps and rough effort. If no enhancement is warranted, say "No enhancements needed this week." '
            . 'Format your answer with exactly two sections, each starting on its own line: '
            . '"## Analysis" then "## Implementation Plan".',
            $context
        );

        $analysis = null;
        $plan = 'No enhancements needed this week.';
        $aiError = null;

        if ($aiResult['success'] && $aiResult['text'] !== null) {
            [$analysis, $plan] = $this->splitAiReport($aiResult['text']);
        } else {
            $aiError = (string) ($aiResult['error'] ?? 'AI analysis unavailable.');
        }

        $hasErrors = $cronErrors !== [] || $phpErrors !== [];

        $variables = [
            'report_date' => $now->format('F j, Y'),
            'status_banner' => $hasErrors
                ? '<p><strong style="color:#b91c1c;">⚠️ Issues were detected in the last 7 days.</strong> The error log and AI analysis are below.</p>'
                : '<p><strong style="color:#16803d;">✅ All clear.</strong> No cron or PHP errors were recorded in the last 7 days.</p>',
            'errors_section' => $this->errorsHtml($cronErrors, $phpErrors),
            'ai_analysis_block' => $analysis !== null
                ? '<div style="background:#fff;border:1px solid #eef0f3;border-radius:8px;padding:16px;white-space:pre-wrap;">' . $analysis . '</div>'
                : '<p style="color:#b91c1c;">AI analysis unavailable' . ($aiError !== null ? ' — ' . htmlspecialchars($aiError, ENT_QUOTES, 'UTF-8') : '') . '. Showing the raw error log above instead.</p>',
            'implementation_plan' => $plan,
            'admin_url' => rtrim((string) $this->config->env('APP_URL', ''), '/') . '/admin',
            'company_name' => (string) ($this->settings->get('theme.brand_name', 'CodeVault') ?: 'CodeVault'),
        ];

        $this->mail->sendTemplate('ai_system_report', $recipient, $variables);

        $this->settings->set('automation.ai_health_last_run', $now->format('Y-m-d'));
    }

    /**
     * @param array<int, array{job_name: string, error_message: string}> $cronErrors
     * @param array<int, string> $phpErrors
     */
    private function buildContext(array $cronErrors, array $phpErrors): string
    {
        $lines = ['Weekly system health scan — errors recorded in the last 7 days:', ''];

        if ($cronErrors === [] && $phpErrors === []) {
            $lines[] = 'No errors detected.';
        } else {
            if ($cronErrors !== []) {
                $lines[] = 'CRON JOB FAILURES (' . count($cronErrors) . '):';
                foreach ($cronErrors as $error) {
                    $lines[] = '- [' . $error['job_name'] . '] ' . $error['error_message'];
                }
            }

            if ($phpErrors !== []) {
                $lines[] = '';
                $lines[] = 'PHP ERROR LOG (last ' . count($phpErrors) . ' lines):';
                foreach ($phpErrors as $line) {
                    $lines[] = '  ' . $line;
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Split the AI's answer on the "## Implementation Plan" heading. Falls
     * back to the whole text as analysis if the marker is missing.
     *
     * @return array{0: string, 1: string}
     */
    private function splitAiReport(string $text): array
    {
        $marker = '## Implementation Plan';
        $pos = stripos($text, $marker);

        if ($pos === false) {
            return [trim($text), 'No enhancements needed this week.'];
        }

        $analysis = trim(substr($text, 0, $pos));
        $plan = trim(substr($text, $pos + strlen($marker)));

        // Drop the "## Analysis" heading if the model echoed it.
        $analysis = preg_replace('/^##\s*Analysis\s*\n/i', '', $analysis) ?? $analysis;

        return [$analysis, $plan !== '' ? $plan : 'No enhancements needed this week.'];
    }

    /**
     * @param array<int, array{job_name: string, error_message: string}> $cronErrors
     * @param array<int, string> $phpErrors
     */
    private function errorsHtml(array $cronErrors, array $phpErrors): string
    {
        if ($cronErrors === [] && $phpErrors === []) {
            return '';
        }

        $html = '<h3 style="margin-top:24px;">📋 Error Log</h3><div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:16px;font-family:monospace;font-size:12px;overflow-x:auto;">';

        foreach ($cronErrors as $error) {
            $html .= '<div style="margin-bottom:8px;"><strong>' . htmlspecialchars($error['job_name'], ENT_QUOTES, 'UTF-8') . '</strong><br>'
                . htmlspecialchars($error['error_message'], ENT_QUOTES, 'UTF-8') . '</div>';
        }

        if ($phpErrors !== []) {
            $html .= '<div style="margin-top:8px;">' . htmlspecialchars(implode("\n", $phpErrors), ENT_QUOTES, 'UTF-8') . '</div>';
        }

        return $html . '</div>';
    }

    /** Last 80 lines of the PHP error log, newest last. */
    private function phpErrorTail(): array
    {
        $path = dirname(__DIR__, 2) . '/storage/cache/php-error.log';

        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return [];
        }

        return array_slice(array_map('trim', $lines), -80);
    }

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
}
