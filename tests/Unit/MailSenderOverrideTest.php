<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Mail\Mailer;
use CodeVault\Mail\EmailLogRepository;
use CodeVault\Mail\SendEmailJob;
use CodeVault\Mail\SmtpMailer;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * The per-message sender actually reaches the transport, and is validated on the way.
 *
 * WHY THIS IS TESTABLE WITHOUT A MAIL SERVER, AND WITHOUT MOCKING THE SOCKET
 *
 * SmtpMailer validates BOTH addresses — and refuses a sender — before it resolves a
 * host or opens a socket. So pointing the host at something it deliberately rejects
 * ('sendmail' is in its own pseudo-host list) makes every send fail at a known place,
 * and the MESSAGE that comes back says exactly how far the call got. That turns
 * "was the override used?" into an observable difference:
 *
 *   - configured sender deliberately broken + a valid override  -> the complaint is
 *     about the SMTP host, so the configured address was REPLACED and validation passed;
 *   - the same broken sender with NO override                   -> the complaint is about
 *     the From address, which proves the configured value would otherwise have failed.
 *
 * Neither direction alone proves anything; together they pin the behaviour.
 */
final class MailSenderOverrideTest extends DatabaseTestCase
{
    private SettingsRepository $settings;
    private SmtpMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $configDir = sys_get_temp_dir() . '/codevault-mail-test-' . uniqid();
        mkdir($configDir);

        $this->settings = new SettingsRepository($this->db);
        $this->mailer = new SmtpMailer($this->settings, new Config($configDir));

        // A host the transport refuses on purpose, so NO test here can reach the
        // network — and the failure point is deterministic.
        $this->settings->set('smtp.host', 'sendmail');
        $this->settings->set('smtp.from_name', 'Platform Support');
    }

    /** @return string the message the transport complained with */
    private function sending(?array $from): string
    {
        try {
            $this->mailer->send('someone@example.test', 'Subject', '<p>Hi</p>', $from);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return '';
    }

    public function test_a_store_sender_replaces_the_configured_one_for_that_message_only(): void
    {
        // The configured sender is unusable. Without an override that must be what the
        // complaint is about...
        $this->settings->set('smtp.from_email', 'not-an-address');

        $this->assertStringContainsString(
            'invalid From email address',
            $this->sending(null),
            'A broken configured sender must be refused.'
        );

        // ...and WITH the override the complaint must be about something later in the
        // same method, which is only reachable if the override replaced it.
        $withOverride = $this->sending(['name' => 'Acme Hosting', 'email' => 'support@acme.test']);

        $this->assertStringNotContainsString(
            'From email address',
            $withOverride,
            'The store address must be used in place of the configured one.'
        );
        $this->assertStringContainsString(
            'SMTP host',
            $withOverride,
            'The call should have got past address validation and reached the host check.'
        );
    }

    public function test_a_name_override_does_not_remove_the_configured_address(): void
    {
        // The shape used for a store that has no address of its own: its NAME goes on
        // the message and the platform's address still carries it. That must not be
        // mistaken for "no sender at all".
        $this->settings->set('smtp.from_email', 'not-an-address');

        $this->assertStringContainsString(
            'invalid From email address',
            $this->sending(['name' => 'Acme Hosting']),
            'A name alone must leave the configured address in place, not blank it.'
        );
    }

    public function test_a_sender_override_carrying_a_line_break_is_refused(): void
    {
        // The override is user input — a reseller typed it — and it is written into
        // MAIL FROM: and the From: header verbatim. This is the SMTP-injection case,
        // and it is the reason the override is applied BEFORE the hygiene check rather
        // than after it.
        $message = $this->sending(['email' => "support@acme.test>\r\nRCPT TO: <victim@example.test>"]);

        $this->assertStringContainsString('line break', $message);
        $this->assertStringNotContainsString('RCPT TO: <victim', $message, 'the crafted value must never be echoed back into the dialogue');
    }

    public function test_an_empty_override_email_keeps_the_configured_sender(): void
    {
        $this->settings->set('smtp.from_email', 'not-an-address');

        // An empty string is "not set", not "send as nobody" — a cleared field on the
        // store form must not blank the sender.
        foreach ([['email' => ''], ['email' => '   '], []] as $from) {
            $this->assertStringContainsString(
                'invalid From email address',
                $this->sending($from),
                'An empty override must leave the configured address alone.'
            );
        }
    }

    // ------------------------------------------------------- the queued job ---

    public function test_the_queued_job_hands_the_sender_to_the_transport(): void
    {
        // The dispatcher only QUEUES. If the job drops the sender, every white-labelled
        // message silently goes out from the platform instead — and nothing else in the
        // suite would notice, because the email still sends.
        $recorder = new class implements Mailer {
            /** @var array<int, array<string, mixed>> */
            public array $sent = [];

            public function send(string $to, string $subject, string $html, ?array $from = null): void
            {
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'from' => $from];
            }
        };

        $container = new Container();
        $container->instance(Database::class, $this->db);
        $container->instance(Mailer::class, $recorder);
        App::setContainer($container);

        $logId = (new EmailLogRepository($this->db))->create('someone@example.test', 'Subject', 'ticket_reply', null);

        (new SendEmailJob(
            $logId,
            'someone@example.test',
            'Subject',
            '<p>Hi</p>',
            ['name' => 'Acme Hosting', 'email' => 'support@acme.test']
        ))->handle();

        $this->assertCount(1, $recorder->sent);
        $this->assertSame(
            ['name' => 'Acme Hosting', 'email' => 'support@acme.test'],
            $recorder->sent[0]['from'],
            'The sender must survive the queue, or white-labelling is lost silently.'
        );
    }

    public function test_a_job_queued_without_a_sender_sends_none(): void
    {
        // Backwards compatibility for anything already serialized in the queue by an
        // earlier release: no sender means the configured one is used, not a failure.
        $recorder = new class implements Mailer {
            /** @var array<int, array<string, mixed>> */
            public array $sent = [];

            public function send(string $to, string $subject, string $html, ?array $from = null): void
            {
                $this->sent[] = ['from' => $from];
            }
        };

        $container = new Container();
        $container->instance(Database::class, $this->db);
        $container->instance(Mailer::class, $recorder);
        App::setContainer($container);

        $logId = (new EmailLogRepository($this->db))->create('someone@example.test', 'Subject', null, null);

        (new SendEmailJob($logId, 'someone@example.test', 'Subject', '<p>Hi</p>'))->handle();

        $this->assertCount(1, $recorder->sent);
        $this->assertNull($recorder->sent[0]['from']);
    }
}
