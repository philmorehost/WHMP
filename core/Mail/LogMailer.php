<?php

declare(strict_types=1);

namespace CodeVault\Mail;

/**
 * Dev-safe default transport: writes the rendered email to a local log
 * file instead of transmitting it. No SMTP server is configured in this
 * environment, so this is bound in place of a real transport (blueprint §5
 * "Async email") — same graceful-degrade posture as NullGeoIpResolver and
 * SyncQueue. Swap in a real SMTP-backed Mailer once credentials exist; the
 * rest of the async-email pipeline (queue, log, templates) doesn't change.
 */
final class LogMailer implements Mailer
{
    public function __construct(
        private readonly string $logPath
    ) {
    }

    public function send(string $to, string $subject, string $html): void
    {
        $line = sprintf(
            "[%s] To: %s | Subject: %s | Body: %s\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            preg_replace('/\s+/', ' ', strip_tags($html))
        );

        file_put_contents($this->logPath, $line, FILE_APPEND);
    }
}
