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
 * "Write copy for me" for promo banners — the AI only ever drafts short plain
 * text (eyebrow/headline/subtext/CTA) into the form; the actual visual comes
 * from PromoBannerTemplates, which the admin picks separately. There is no
 * image-generation provider in this codebase, so "AI-designed" here means
 * AI-written copy laid over a curated template, not a generated graphic.
 */
final class PromoBannerCopilotController
{
    private const FEATURE = 'promo_banner_copilot';

    private const SYSTEM_PROMPT =
        "You write short copy for a promotional popup on a web hosting and domain company's website, "
        . "advertising a discount coupon code. Return exactly this structure and nothing else:\n"
        . "EYEBROW: <a short punchy line under 40 characters, e.g. a customer-count or excitement hook>\n"
        . "HEADLINE: <the main line under 60 characters, must reference the discount>\n"
        . "SUBTEXT: <one supporting sentence under 100 characters, optional context>\n"
        . "CTA: <a 1-3 word button label, e.g. \"Apply Now\", \"Claim Offer\">\n"
        . "Plain text only — no HTML, no Markdown, no quotes around the values, no emoji unless it reads naturally. "
        . "Do not invent a discount percentage/amount other than the one given to you.";

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

        if (!$this->guard->can(PermissionRegistry::PROMOTIONS_MANAGE)) {
            return Response::json(['success' => false, 'message' => 'You do not have permission to use this.'], 403);
        }

        if (!$this->aiSettings->isFeatureEnabled(self::FEATURE)) {
            return Response::json(['success' => false, 'message' => 'The promo banner copilot is switched off under Configuration → AI.']);
        }

        if (!$this->aiSettings->hasKey()) {
            return Response::json(['success' => false, 'message' => 'No AI API key is configured. Add one under Configuration → AI.']);
        }

        $couponCode = trim((string) $request->input('coupon_code', ''));
        $discount = trim((string) $request->input('discount_description', ''));
        $brief = trim((string) $request->input('brief', ''));

        if ($couponCode === '' || $discount === '') {
            return Response::json(['success' => false, 'message' => 'Enter the coupon code and its discount first, then generate copy.']);
        }

        $result = $this->ai->complete(self::SYSTEM_PROMPT, $this->buildPrompt($couponCode, $discount, $brief));

        if (($result['success'] ?? false) !== true) {
            return Response::json([
                'success' => false,
                'message' => (string) ($result['error'] ?? 'The AI service did not respond.'),
            ]);
        }

        $parsed = self::parseResponse((string) $result['text']);

        if ($parsed['headline'] === '') {
            return Response::json(['success' => false, 'message' => 'The AI returned an empty draft. Try rephrasing the brief.']);
        }

        return Response::json([
            'success' => true,
            'eyebrow_text' => $parsed['eyebrow'],
            'headline' => $parsed['headline'],
            'subtext' => $parsed['subtext'],
            'cta_text' => $parsed['cta'],
            'message' => null,
        ]);
    }

    private function buildPrompt(string $couponCode, string $discount, string $brief): string
    {
        $company = trim((string) ($this->settings->get('theme.brand_name', '') ?? '')) ?: 'our company';

        return "Company: {$company}\n"
            . "Coupon code: {$couponCode}\n"
            . "Discount: {$discount}\n"
            . ($brief !== '' ? "Extra context: {$brief}\n" : '')
            . "Write the popup copy for this offer.";
    }

    /**
     * @return array{eyebrow: string, headline: string, subtext: string, cta: string}
     */
    public static function parseResponse(string $raw): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $raw));
        $text = preg_replace('/^```[a-zA-Z]*\n?|\n?```$/', '', $text) ?? $text;

        $extract = static function (string $label, string $text): string {
            if (preg_match('/^\s*' . $label . ':\s*(.+)$/mi', $text, $m) === 1) {
                return trim(strip_tags($m[1]), " \t\"'");
            }

            return '';
        };

        return [
            'eyebrow' => mb_substr($extract('EYEBROW', $text), 0, 120),
            'headline' => mb_substr($extract('HEADLINE', $text), 0, 150),
            'subtext' => mb_substr($extract('SUBTEXT', $text), 0, 300),
            'cta' => mb_substr($extract('CTA', $text), 0, 40),
        ];
    }
}
