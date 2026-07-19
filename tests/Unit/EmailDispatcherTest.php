<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Container;
use CodeVault\Database\Migrator;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Mail\EmailLogRepository;
use CodeVault\Mail\EmailTemplateRepository;
use CodeVault\Mail\LogMailer;
use CodeVault\Mail\Mailer;
use CodeVault\Queue\SyncQueue;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;
use RuntimeException;

final class EmailDispatcherTest extends DatabaseTestCase
{
    private EmailDispatcher $dispatcher;
    private EmailLogRepository $log;
    private string $mailLogPath;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->mailLogPath = sys_get_temp_dir() . '/codevault-mail-test-' . uniqid() . '.log';

        $templates = new EmailTemplateRepository($this->db);
        $this->log = new EmailLogRepository($this->db);
        $queue = new SyncQueue();

        $this->dispatcher = new EmailDispatcher($templates, $this->log, $queue);

        // SendEmailJob resolves Mailer/EmailLogRepository via the App
        // service locator (it must be plain-data serializable for Redis —
        // see SendEmailJob's docblock), so a real container needs to be set.
        $container = new Container();
        $container->instance(Mailer::class, new LogMailer($this->mailLogPath));
        $container->instance(EmailLogRepository::class, $this->log);
        App::setContainer($container);
    }

    protected function tearDown(): void
    {
        @unlink($this->mailLogPath);
        parent::tearDown();
    }

    public function test_send_template_renders_variables_and_delivers_via_sync_queue(): void
    {
        $logId = $this->dispatcher->sendTemplate('client_welcome', 'jane@example.test', [
            'first_name' => 'Jane',
            'email' => 'jane@example.test',
            'company_name' => 'CodeVault',
        ], 42);

        $entries = $this->log->recent(10);
        $this->assertCount(1, $entries);
        $this->assertSame('sent', $entries[0]['status']);
        $this->assertSame('Welcome to CodeVault, Jane!', $entries[0]['subject']);
        $this->assertSame(42, (int) $entries[0]['client_id']);

        $mailLogContents = file_get_contents($this->mailLogPath);
        $this->assertStringContainsString('jane@example.test', $mailLogContents);
        $this->assertStringContainsString('Welcome to CodeVault, Jane!', $mailLogContents);
    }

    public function test_send_template_throws_for_an_unknown_template_key(): void
    {
        $this->expectException(RuntimeException::class);

        $this->dispatcher->sendTemplate('does_not_exist', 'jane@example.test', []);
    }

    public function test_client_id_is_optional(): void
    {
        $this->dispatcher->sendTemplate('client_welcome', 'jane@example.test', [
            'first_name' => 'Jane',
            'email' => 'jane@example.test',
            'company_name' => 'CodeVault',
        ]);

        $this->assertNull($this->log->recent(1)[0]['client_id']);
    }
}
