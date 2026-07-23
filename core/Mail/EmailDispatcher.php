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
        private readonly QueueInterface $queue,
        private readonly ?\CodeVault\Settings\SettingsRepository $settings = null,
        private readonly ?\CodeVault\Config $config = null
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
        $contentHtml = $this->render($template['body_html'], $variables);

        $html = $this->wrapInModernLayout($subject, $contentHtml);

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
        $wrappedHtml = $this->wrapInModernLayout($subject, $html);

        $logId = $this->log->create($toEmail, $subject, null, $clientId);

        $this->queue->push(new SendEmailJob($logId, $toEmail, $subject, $wrappedHtml));

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

    private function wrapInModernLayout(string $subject, string $contentHtml): string
    {
        if (str_contains(strtolower($contentHtml), '<html') || str_contains(strtolower($contentHtml), '<!doctype html')) {
            return $contentHtml;
        }

        $brandName = 'CodeVault';
        $appUrl = 'http://localhost';

        if ($this->settings !== null) {
            $brandName = $this->settings->get('theme.brand_name', 'CodeVault') ?: 'CodeVault';
        }
        if ($this->config !== null) {
            $appUrl = rtrim($this->config->env('APP_URL', 'http://localhost'), '/');
        }

        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        .wrapper {
            width: 100%;
            table-layout: fixed;
            background-color: #f8fafc;
            padding: 40px 20px;
            box-sizing: border-box;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            padding: 32px;
            text-align: center;
        }
        .header h1 {
            color: #ffffff;
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.025em;
        }
        .content {
            padding: 32px;
            font-size: 16px;
            line-height: 1.6;
            color: #334155;
        }
        .content p {
            margin: 0 0 16px 0;
        }
        .content p:last-child {
            margin-bottom: 0;
        }
        .footer {
            background-color: #f1f5f9;
            padding: 24px;
            text-align: center;
            font-size: 13px;
            color: #64748b;
            border-top: 1px solid #e2e8f0;
        }
        .footer p {
            margin: 0 0 8px 0;
        }
        .footer p:last-child {
            margin-bottom: 0;
        }
        .footer a {
            color: #2563eb;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <div class="header">
                <h1>{$brandName}</h1>
            </div>
            <div class="content">
                {$contentHtml}
            </div>
            <div class="footer">
                <p>&copy; {$year} {$brandName}. All rights reserved.</p>
                <p>Need help? Contact our <a href="{$appUrl}/client/tickets">Support Department</a>.</p>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
