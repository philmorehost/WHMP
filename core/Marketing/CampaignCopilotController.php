<?php

declare(strict_types=1);

namespace CodeVault\Marketing;

use CodeVault\Ai\AiProvider;
use CodeVault\Ai\AiSettings;
use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Staff\PermissionRegistry;

/**
 * "Help me write" / "Refine" for marketing campaigns.
 *
 * Drafts or rewrites a campaign's subject and body from a short brief, so an
 * admin starts from something editable instead of a blank textarea. It only
 * ever returns text to the form — it never creates or sends a campaign, so a
 * bad suggestion costs nothing but a click.
 *
 * Unlike Ask AI, the output here is deliberately HTML: the campaign body field
 * is HTML and goes into the email as-is. The model is constrained to a small
 * tag set that email clients actually render (no <div>, no CSS, no <script>),
 * and the result is sanitised on the way out rather than trusted.
 */
final class CampaignCopilotController
{
    private const FEATURE = 'marketing_copilot';

    /** Tags an email client will render reliably. Everything else is stripped. */
    private const ALLOWED_TAGS = '<p><br><strong><b><em><i><ul><ol><li><a><h2><h3><blockquote>';

    private const SYSTEM_PROMPT =
        "You write marketing emails for a web hosting and domain company, addressed to its existing customers. "
        . "Return exactly this structure and nothing else:\n"
        . "SUBJECT: <a single-line subject, under 70 characters, no quotes>\n"
        . "---BODY---\n"
        . "<the email body as simple HTML>\n\n"
        . "Rules for the body: use only <p>, <strong>, <em>, <ul>, <ol>, <li>, <a href>, <h2>, <h3> and <br>. "
        . "No <div>, no style attributes, no CSS, no scripts, no <html> or <body> wrapper, no Markdown. "
        . "Keep it concise and specific — short paragraphs, no filler. "
        . "Use [Client Name] as the greeting placeholder if a name is needed. "
        . "Do not invent prices, dates, discounts or feature names that were not given to you. "
        . "Do not add a subject line or sign-off inside the body unless asked.";

    public function __construct(
        private readonly AuthGuard $guard,
        private readonly AiProvider $ai,
        private readonly AiSettings $aiSettings,
        private readonly SettingsRepository $settings
    ) {
    }

    public function generate(Request $request): Response
    {
        if (!$this->guard->check()) {
            return Response::json(['success' => false, 'message' => 'Please log in.'], 401);
        }

        if (!$this->guard->can(PermissionRegistry::SETTINGS_MANAGE)) {
            return Response::json(['success' => false, 'message' => 'You do not have permission to use this.'], 403);
        }

        if (!$this->aiSettings->isFeatureEnabled(self::FEATURE)) {
            return Response::json(['success' => false, 'message' => 'The marketing copilot is switched off under Configuration → AI.']);
        }

        if (!$this->aiSettings->hasKey()) {
            return Response::json(['success' => false, 'message' => 'No AI API key is configured. Add one under Configuration → AI.']);
        }

        $mode = (string) $request->input('mode', 'write') === 'refine' ? 'refine' : 'write';
        $brief = trim((string) $request->input('brief', ''));
        $subject = trim((string) $request->input('subject', ''));
        $body = trim((string) $request->input('body', ''));

        if ($mode === 'refine' && $subject === '' && $body === '') {
            return Response::json(['success' => false, 'message' => 'Write a subject or body first, then Refine it.']);
        }

        if ($mode === 'write' && $brief === '') {
            return Response::json(['success' => false, 'message' => 'Describe what the campaign should say.']);
        }

        $result = $this->ai->complete(self::SYSTEM_PROMPT, $this->buildPrompt($mode, $brief, $subject, $body));

        if (($result['success'] ?? false) !== true) {
            return Response::json([
                'success' => false,
                'message' => (string) ($result['error'] ?? 'The AI service did not respond.'),
            ]);
        }

        $parsed = self::parseResponse((string) $result['text']);

        if ($parsed['body'] === '') {
            return Response::json(['success' => false, 'message' => 'The AI returned an empty draft. Try rephrasing the brief.']);
        }

        return Response::json([
            'success' => true,
            'subject' => $parsed['subject'],
            'body' => $parsed['body'],
            'message' => null,
        ]);
    }

    private function buildPrompt(string $mode, string $brief, string $subject, string $body): string
    {
        // Give the model the company name so it doesn't invent one.
        $company = trim((string) ($this->settings->get('theme.brand_name', '') ?? '')) ?: 'our company';

        if ($mode === 'refine') {
            return "Company: {$company}\n\n"
                . "Improve the campaign email below. Keep its meaning and any specific facts, prices or dates "
                . "exactly as they are — tighten the wording, fix grammar, and make it clearer and more engaging.\n\n"
                . ($brief !== '' ? "Extra instructions: {$brief}\n\n" : '')
                . "Current subject: {$subject}\n\n"
                . "Current body:\n{$body}";
        }

        return "Company: {$company}\n\n"
            . "Write a campaign email about the following:\n{$brief}"
            . ($subject !== '' ? "\n\nThe admin has drafted this subject, improve on it: {$subject}" : '');
    }

    /**
     * Splits the model's reply into subject and body and strips anything
     * outside the allowed tag set.
     *
     * Tolerant by design: models sometimes drop the SUBJECT line or the
     * separator. Rather than failing, whatever came back becomes the body so
     * the admin still has something to edit.
     *
     * @return array{subject: string, body: string}
     */
    public static function parseResponse(string $raw): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $raw));

        // Models like to wrap the whole answer in a code fence.
        $text = preg_replace('/^```[a-zA-Z]*\n?|\n?```$/', '', $text) ?? $text;

        $subject = '';

        if (preg_match('/^\s*SUBJECT:\s*(.+)$/mi', $text, $m) === 1) {
            $subject = trim($m[1], " \t\"'");
            $text = (string) preg_replace('/^\s*SUBJECT:\s*.+$/mi', '', $text, 1);
        }

        $parts = preg_split('/^\s*-{2,}\s*BODY\s*-{2,}\s*$/mi', $text, 2);
        $body = trim($parts[1] ?? $parts[0] ?? '');

        // Drop <script>/<style> with their contents first: strip_tags() removes
        // the tags but keeps the text between them, which would otherwise leave
        // "alert('x')" or raw CSS sitting in the email as visible copy.
        $body = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $body) ?? $body;
        $body = preg_replace('#<(script|style)\b[^>]*>.*$#is', '', $body) ?? $body;

        // Never trust model output as markup — keep only tags email clients
        // reliably render.
        $body = strip_tags($body, self::ALLOWED_TAGS);

        // Strip event handlers and javascript: URLs that survive strip_tags
        // because they are attributes on allowed tags.
        $body = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $body) ?? $body;
        $body = preg_replace('/href\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*\1/i', 'href="#"', $body) ?? $body;

        return ['subject' => $subject, 'body' => trim($body)];
    }
}
