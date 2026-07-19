<?php

declare(strict_types=1);

namespace CodeVault\Mail;

use CodeVault\Queue\QueueInterface;
use RuntimeException;

/**
 * The one entry point engines call to send a templated email (blueprint §5
 * "Async email"): render the stored template with variables, log it, and
 * queue delivery — never sends synchronously in the request path.
 */
final class EmailDispatcher
{
    public function __construct(
        private readonly EmailTemplateRepository $templates,
        private readonly EmailLogRepository $log,
        private readonly QueueInterface $queue
    ) {
    }

    /**
     * @param array<string, string> $variables substituted into {{key}} placeholders
     */
    public function sendTemplate(string $templateKey, string $toEmail, array $variables = [], ?int $clientId = null): int
    {
        $template = $this->templates->findByKey($templateKey);

        if ($template === null) {
            throw new RuntimeException("Email template [{$templateKey}] does not exist.");
        }

        $subject = $this->render($template['subject'], $variables);
        $html = $this->render($template['body_html'], $variables);

        $logId = $this->log->create($toEmail, $subject, $templateKey, $clientId);

        $this->queue->push(new SendEmailJob($logId, $toEmail, $subject, $html));

        return $logId;
    }

    /**
     * Same log+queue path as sendTemplate(), for already-composed content
     * with no stored template — e.g. an admin-authored mass-mail campaign
     * (blueprint §5 marketing automation), where the subject/body come
     * straight from what staff typed, not a {{key}} template lookup.
     */
    public function sendRaw(string $subject, string $html, string $toEmail, ?int $clientId = null): int
    {
        $logId = $this->log->create($toEmail, $subject, null, $clientId);

        $this->queue->push(new SendEmailJob($logId, $toEmail, $subject, $html));

        return $logId;
    }

    /** @param array<string, string> $variables */
    private function render(string $template, array $variables): string
    {
        $replacements = [];

        foreach ($variables as $key => $value) {
            $replacements['{{' . $key . '}}'] = $value;
        }

        return strtr($template, $replacements);
    }
}
