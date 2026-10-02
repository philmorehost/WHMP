<?php

declare(strict_types=1);

namespace CodeVault\Mail;

use CodeVault\Queue\Job;
use CodeVault\Support\App;
use Throwable;

/**
 * Plain-data queue payload (blueprint §3 "Queue/worker" — async email,
 * §5). Holds only serializable scalars; resolves the Mailer/log repository
 * at handle() time via App::container() so it works whether it's run
 * inline (SyncQueue) or unserialized in a separate worker process (RedisQueue).
 */
final class SendEmailJob implements Job
{
    public function __construct(
        public readonly int $logId,
        public readonly string $to,
        public readonly string $subject,
        public readonly string $html,
        // Appended last WITH a default, so a job already serialized in the queue by an
        // earlier release still unserializes: a white-label message keeps the sender it
        // was queued with, and every other message falls back to the configured one.
        // Making this required would have turned a deploy into a queue of failed sends.
        public readonly ?array $from = null
    ) {
    }

    public function queue(): string
    {
        return 'email';
    }

    public function handle(): void
    {
        $container = App::container();

        /** @var Mailer $mailer */
        $mailer = $container->make(Mailer::class);
        /** @var EmailLogRepository $log */
        $log = $container->make(EmailLogRepository::class);

        try {
            $mailer->send($this->to, $this->subject, $this->html, $this->from);
            $log->markSent($this->logId);
        } catch (Throwable $e) {
            $log->markFailed($this->logId, $e->getMessage());
        }
    }
}
