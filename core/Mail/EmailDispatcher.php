<?php

declare(strict_types=1);

namespace CodeVault\Mail;

use CodeVault\Notifications\ClientNotificationRepository;
use CodeVault\Queue\QueueInterface;
use CodeVault\Reseller\StoreMailBranding;
use RuntimeException;
use Throwable;

/**
 * The one entry point engines call to send a templated email (blueprint §5
 * "Async email"): render the stored template with variables, log it, and
 * queue delivery — never sends synchronously in the request path.
 *
 * WHITE LABEL: EVERY MESSAGE IS SENT AS SOMEBODY
 *
 * Either as the platform, or as the reseller store whose customer it is for — decided
 * per message by StoreMailBranding (see that class for the order of the rules). A
 * store's message carries the store's From name and address, the store's name in
 * {{company_name}}, links to the store's own site, and the store's name, logo and
 * colour in the shell; anything of ours typed into a template is rewritten out.
 *
 * A caller that KNOWS whose message it is (a ticket belongs to a store whatever site
 * the reply was written on) says so with onBehalfOfStore() / onBehalfOfPlatform(),
 * which skips the guessing. Everyone else calls sendTemplate() exactly as before.
 */
final class EmailDispatcher
{
    /** True when a caller has said explicitly whose message this is. */
    private bool $forced = false;

    /** @var array<string, mixed>|null the store a forced message is for; null = platform */
    private ?array $forcedStore = null;

    public function __construct(
        private readonly EmailTemplateRepository $templates,
        private readonly EmailLogRepository $log,
        private readonly QueueInterface $queue,
        private readonly ?\CodeVault\Settings\SettingsRepository $settings = null,
        private readonly ?\CodeVault\Config $config = null,
        private readonly ?ClientNotificationRepository $notifications = null,
        // Optional so the unit tests that build a dispatcher by hand keep working;
        // without it every message is the platform's, which is what they assert.
        private readonly ?StoreMailBranding $branding = null
    ) {
    }

    /**
     * A dispatcher whose messages are written as this store, whoever they are to.
     * A copy, never a mutation: the container shares one dispatcher per request.
     *
     * @param array<string, mixed> $store
     */
    public function onBehalfOfStore(array $store): self
    {
        $copy = clone $this;
        $copy->forced = true;
        $copy->forcedStore = $store;

        return $copy;
    }

    /** A dispatcher whose messages are the platform's — staff mail, mostly. */
    public function onBehalfOfPlatform(): self
    {
        $copy = clone $this;
        $copy->forced = true;
        $copy->forcedStore = null;

        return $copy;
    }

    /**
     * @param array<string, string> $variables substituted into {{key}} placeholders
     */
    public function sendTemplate(string $templateKey, string $toEmail, array $variables = [], ?int $clientId = null, ?array $from = null): int
    {
        $template = $this->templates->findByKey($templateKey);

        if ($template === null) {
            throw new RuntimeException("Email template [{$templateKey}] does not exist.");
        }

        $brand = $this->brandFor($toEmail, $clientId);

        if ($brand !== null) {
            $variables = StoreMailBranding::brandVariables($variables, $brand);
        }

        $subject = $this->render($template['subject'], $variables);
        $contentHtml = $this->render($template['body_html'], $variables);

        if ($brand !== null) {
            $subject = StoreMailBranding::rebrand($subject, $brand, false);
            $contentHtml = StoreMailBranding::rebrand($contentHtml, $brand, true);
        }

        $html = $this->wrapInModernLayout($subject, $contentHtml, $brand);

        $logId = $this->log->create($toEmail, $subject, $templateKey, $clientId);
        $this->mirrorToNotifications($subject, $contentHtml, $clientId, $logId);

        $this->queue->push(new SendEmailJob($logId, $toEmail, $subject, $html, $this->senderFor($from, $brand)));

        return $logId;
    }

    /**
     * Same log+queue path as sendTemplate(), for already-composed content
     * with no stored template — e.g. an admin-authored mass-mail campaign
     * (blueprint §5 marketing automation), where the subject/body come
     * straight from what staff typed, not a {{key}} template lookup.
     *
     * PASS THE BODY AS THE AUTHOR TYPED IT — plain text in, HTML out. Do NOT
     * pre-escape it or pre-convert newlines to <br />: wrapInModernLayout()
     * already converts plain prose through FormattedText::toHtml(), and converting
     * it twice is a real, shipped bug rather than a theoretical one (commit: the
     * admin cancellation report arrived showing a literal <br /> on every line and
     * the client address as &lt;...&gt;).
     *
     * The reason a double conversion is so easy to introduce: FormattedText::
     * toHtml() decides "already HTML, leave alone" from isHtml(), which matches
     * only BLOCK tags (p, div, table, ...). A body whose only markup is <br />
     * fails that test, so it gets escaped again — the tags become visible text.
     */
    public function sendRaw(string $subject, string $html, string $toEmail, ?int $clientId = null, ?array $from = null): int
    {
        $brand = $this->brandFor($toEmail, $clientId);

        if ($brand !== null) {
            $subject = StoreMailBranding::rebrand($subject, $brand, false);
            $html = StoreMailBranding::rebrand($html, $brand, true);
        }

        $wrappedHtml = $this->wrapInModernLayout($subject, $html, $brand);

        $logId = $this->log->create($toEmail, $subject, null, $clientId);
        $this->mirrorToNotifications($subject, $html, $clientId, $logId);

        $this->queue->push(new SendEmailJob($logId, $toEmail, $subject, $wrappedHtml, $this->senderFor($from, $brand)));

        return $logId;
    }

    /**
     * The store this message is written as, with everything needed to brand it — or
     * null for the platform.
     *
     * FAILS OPEN TO THE PLATFORM, and that is a judgement: a lookup failure here
     * (the database blinked) can produce a platform-branded message, but failing
     * closed would produce NO message, and a customer who never receives their
     * invoice or password reset is worse off than one who sees the wrong logo.
     *
     * @return array<string, mixed>|null
     */
    private function brandFor(string $toEmail, ?int $clientId): ?array
    {
        if ($this->branding === null) {
            return null;
        }

        try {
            $store = $this->forced ? $this->forcedStore : $this->branding->storeFor($toEmail, $clientId);

            return $store === null ? null : $this->branding->contextFor($store);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The sender for a store's message: the caller's choice where it made one, with
     * the store's name and address filling whatever it left out — never the
     * configured platform address, which is what an omitted email would fall back to.
     *
     * @param array{email?: string, name?: string}|null $from
     * @param array<string, mixed>|null                 $brand
     * @return array{email?: string, name?: string}|null
     */
    private function senderFor(?array $from, ?array $brand): ?array
    {
        if ($brand === null) {
            return $from;
        }

        $from ??= [];

        if (trim((string) ($from['email'] ?? '')) === '') {
            $from['email'] = (string) $brand['email'];
        }

        if (trim((string) ($from['name'] ?? '')) === '') {
            $from['name'] = (string) $brand['name'];
        }

        return $from;
    }

    /**
     * Every email addressed to a client is also mirrored into their in-app
     * notification center (blueprint: some clients register with a custom
     * email domain that later expires or breaks, and never actually see the
     * real email — this is the guaranteed fallback they'll see the moment
     * they next log in, regardless of whether delivery ever succeeds).
     *
     * Mirrors the pre-wrap content, not wrapInModernLayout()'s branded shell
     * — the shell is header/footer chrome, not the message itself. Best
     * effort: a notification-mirroring failure must never block the actual
     * email, so it's swallowed, same fail-open posture as NotificationDispatcher.
     */
    private function mirrorToNotifications(string $subject, string $html, ?int $clientId, int $emailLogId): void
    {
        if ($clientId === null || $this->notifications === null) {
            return;
        }

        try {
            $this->notifications->sendToOne($subject, $html, $clientId, 'system_email', $emailLogId);
        } catch (Throwable) {
        }
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

    /**
     * Wraps body content in the branded email shell.
     *
     * Table-based with inline styles on every element, which looks dated as
     * web markup but is what email actually requires: Gmail strips <style>
     * blocks on clipped messages, and Outlook renders through Word, which
     * ignores most CSS applied to <div>s. A <style>-driven layout therefore
     * collapses to unstyled text in exactly the clients most customers use.
     *
     * Content is normalised through EmailContent first, so a body typed as
     * plain prose arrives as real paragraphs instead of one unbroken block.
     *
     * A store's message ($brand non-null) gets the store's name, logo, colour and
     * support link — the platform's settings are not read at all on that path, so
     * nothing of ours can reach the shell by accident.
     *
     * @param array<string, mixed>|null $brand
     */
    private function wrapInModernLayout(string $subject, string $contentHtml, ?array $brand = null): string
    {
        if (str_contains(strtolower($contentHtml), '<html') || str_contains(strtolower($contentHtml), '<!doctype html')) {
            return $contentHtml;
        }

        $contentHtml = EmailContent::toHtml($contentHtml);

        $brandName = 'CodeVault';
        $appUrl = 'http://localhost';
        $accent = '#2563eb';

        if ($this->settings !== null) {
            $brandName = $this->settings->get('theme.brand_name', 'CodeVault') ?: 'CodeVault';
            $accent = $this->settings->get('theme.primary_color', $accent) ?: $accent;
        }
        if ($this->config !== null) {
            $appUrl = rtrim($this->config->env('APP_URL', 'http://localhost'), '/');
        }

        $logo = null;

        if ($brand !== null) {
            $brandName = (string) $brand['name'];
            $accent = (string) $brand['color'];
            $appUrl = rtrim((string) $brand['url'], '/');
            $logo = $brand['logo'] ?? null;
        }

        $brandName = htmlspecialchars((string) $brandName, ENT_QUOTES, 'UTF-8');
        $appUrl = htmlspecialchars((string) $appUrl, ENT_QUOTES, 'UTF-8');
        $accent = htmlspecialchars((string) $accent, ENT_QUOTES, 'UTF-8');
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
        $year = date('Y');

        // A logo when the store has one, with its name as the alt text so a client
        // that blocks images still shows who wrote.
        $masthead = $logo === null
            ? "<span style=\"display:inline-block;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:21px;font-weight:700;color:#ffffff;letter-spacing:-0.3px;\">{$brandName}</span>"
            : '<img src="' . htmlspecialchars((string) $logo, ENT_QUOTES, 'UTF-8') . '" alt="' . $brandName . '" style="max-height:48px;max-width:240px;border:0;display:inline-block;" />';

        return <<<HTML
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="x-apple-disable-message-reformatting" />
<title>{$safeSubject}</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;-webkit-font-smoothing:antialiased;">
<!-- Preheader: shown in the inbox list preview, hidden in the message itself. -->
<div style="display:none;font-size:1px;color:#f1f5f9;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">{$safeSubject}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f1f5f9;margin:0;padding:0;">
<tr>
<td align="center" style="padding:32px 16px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background-color:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0;">
<tr>
<td align="center" style="background-color:#0f172a;padding:28px 32px;">
{$masthead}
</td>
</tr>
<tr>
<td style="height:4px;background-color:{$accent};font-size:0;line-height:0;">&nbsp;</td>
</tr>
<tr>
<td style="padding:32px;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.65;color:#334155;">
{$contentHtml}
</td>
</tr>
<tr>
<td style="background-color:#f8fafc;border-top:1px solid #e2e8f0;padding:22px 32px;text-align:center;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;line-height:1.6;color:#64748b;">
<p style="margin:0 0 6px 0;">Need help? <a href="{$appUrl}/client/tickets" style="color:{$accent};text-decoration:none;font-weight:600;">Contact our support team</a>.</p>
<p style="margin:0;color:#94a3b8;">&copy; {$year} {$brandName}. All rights reserved.</p>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
HTML;
    }
}
