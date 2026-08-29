<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Ai\AiProvider;
use CodeVault\Ai\AiSystemHealthJob;
use CodeVault\Auth\AdminRepository;
use CodeVault\Cron\CronRunRepository;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Mail\Mailer;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * The weekly AI system-health scan: gathers cron + PHP errors from the last
 * 7 days, sends them to the AI for analysis + an implementation plan, and
 * emails the admin. Fails open — the raw error log is emailed even when the
 * AI call itself fails.
 */
final class AiSystemHealthJobTest extends DatabaseTestCase
{
    private string $adminEmail = 'ops@example.test';
    /** @var array<int, array{to: string, subject: string, html: string}> */
    private array $sentMails = [];

    protected function setUp(): void
    {
        parent::setUp();
        new \CodeVault\Kernel(dirname(__DIR__, 2));
        $container = \CodeVault\Support\App::container();
        $container->instance(Database::class, $this->db);
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        (new AdminRepository($this->db))->create('ops', $this->adminEmail, 'secret123', 'Ops Admin', null);

        $this->sentMails = [];
        $container->instance(Mailer::class, new class ($this->sentMails) implements Mailer {
            /** @param array<int, array{to: string, subject: string, html: string}> $sink */
            public function __construct(private array &$sink)
            {
            }

            public function send(string $to, string $subject, string $html): void
            {
                $this->sink[] = ['to' => $to, 'subject' => $subject, 'html' => $html];
            }
        });
    }

    public function test_weekly_scan_emails_the_admin_ai_analysis_and_plan_for_detected_errors(): void
    {
        $container = \CodeVault\Support\App::container();

        // Scripted AI: returns an analysis + a concrete implementation plan.
        $fakeAi = new class implements AiProvider {
            public string $lastPrompt = '';

            public function complete(string $systemPrompt, string $userPrompt): array
            {
                $this->lastPrompt = $userPrompt;

                return ['success' => true, 'text' => '## Analysis' . "\n" . 'The recurring_invoices table was missing, which broke the recurring billing cron job.' . "\n" . '## Implementation Plan' . "\n" . 'Add a schema guard so the job skips gracefully until the migration lands.', 'error' => null];
            }
        };
        $container->instance(AiProvider::class, $fakeAi);

        // One failing cron job in the window.
        (new CronRunRepository($this->db))->record(
            'recurring-invoices',
            'error',
            "SQLSTATE[42S02]: Table 'clientmore_whmp.recurring_invoices' doesn't exist",
            [],
            5
        );

        $container->make(AiSystemHealthJob::class)->handle();

        // The admin got the AI health report.
        $emails = $this->db->select("SELECT * FROM email_log WHERE template_key = 'ai_system_report'");
        $this->assertCount(1, $emails);
        $this->assertSame($this->adminEmail, $emails[0]['to_email']);

        $sent = $this->sentMails;
        $this->assertNotEmpty($sent, 'the admin must receive the weekly report');
        $this->assertStringContainsString('Weekly AI System Health Report', $sent[0]['subject']);
        // Raw error log is included (so the admin is notified even before AI).
        $this->assertStringContainsString('recurring-invoices', $sent[0]['html']);
        $this->assertStringContainsString('clientmore_whmp.recurring_invoices', $sent[0]['html']);
        // The AI analysis + implementation plan are rendered.
        $this->assertStringContainsString('schema guard', $sent[0]['html']);
        // The AI prompt actually carried the error.
        $this->assertStringContainsString('SQLSTATE[42S02]', $fakeAi->lastPrompt);
    }

    public function test_weekly_scan_falls_back_to_the_raw_error_log_when_the_ai_is_unavailable(): void
    {
        $container = \CodeVault\Support\App::container();

        $fakeAi = new class implements AiProvider {
            public function complete(string $systemPrompt, string $userPrompt): array
            {
                return ['success' => false, 'text' => null, 'error' => 'DeepSeek API key is not configured.'];
            }
        };
        $container->instance(AiProvider::class, $fakeAi);

        (new CronRunRepository($this->db))->record('recurring-invoices', 'error', 'Table is missing', [], 5);

        $container->make(AiSystemHealthJob::class)->handle();

        // The admin STILL gets the error log, with an explanatory AI note.
        $sent = $this->sentMails;
        $this->assertNotEmpty($sent, 'the error log must never be swallowed');
        $this->assertStringContainsString('Table is missing', $sent[0]['html']);
        $this->assertStringContainsString('AI analysis unavailable', $sent[0]['html']);
    }

    public function test_weekly_scan_does_not_double_send_within_the_same_day(): void
    {
        $container = \CodeVault\Support\App::container();
        $container->instance(AiProvider::class, new class implements AiProvider {
            public function complete(string $systemPrompt, string $userPrompt): array
            {
                return ['success' => true, 'text' => '## Analysis' . "\n" . 'OK', 'error' => null];
            }
        });

        $job = $container->make(AiSystemHealthJob::class);
        $job->handle();
        $job->handle(); // second tick the same day

        $emails = $this->db->select("SELECT * FROM email_log WHERE template_key = 'ai_system_report'");
        $this->assertCount(1, $emails, 'a second run on the same day must not re-send');
    }
}
