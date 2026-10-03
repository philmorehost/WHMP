<?php

declare(strict_types=1);

namespace CodeVault\Marketing;

use CodeVault\Billing\PromotionRepository;
use CodeVault\Request;

/**
 * Reading and validating a promo banner form — shared by the platform's admin
 * page and each reseller's own page so the two cannot disagree about what a
 * valid banner is.
 *
 * The one rule that differs between them is WHOSE coupon a banner may advertise,
 * and that is a parameter rather than a branch: the coupon is looked up in the
 * scope of the site that owns the banner ($resellerId, null = the platform). So a
 * reseller cannot put the platform's code — or another store's — on their popup,
 * and the admin cannot put a store's code on the platform's.
 */
final class PromoBannerInput
{
    public const CTA_DEFAULT = 'Apply Now';

    /**
     * @param string $missingCodeHint where to go to create the code, for the error
     * @return array{0: array<string, mixed>, 1: string|null} [fields, error]
     */
    public static function read(
        Request $request,
        PromotionRepository $promotions,
        ?int $resellerId,
        string $missingCodeHint
    ): array {
        $name = self::clip(trim((string) $request->input('name', '')), 100);
        $template = trim((string) $request->input('template', PromoBannerTemplates::DEFAULT_KEY));
        $eyebrowText = self::clip(trim((string) $request->input('eyebrow_text', '')), 120);
        $headline = self::clip(trim((string) $request->input('headline', '')), 150);
        $subtext = self::clip(trim((string) $request->input('subtext', '')), 300);
        $couponCode = strtoupper(trim((string) $request->input('coupon_code', '')));
        $ctaText = self::clip(trim((string) $request->input('cta_text', '')), 40) ?: self::CTA_DEFAULT;
        $startsAt = self::date((string) $request->input('starts_at', ''));
        $expiresAt = self::date((string) $request->input('expires_at', ''));

        $pagesInput = $request->input('target_pages', []);
        $pagesInput = is_array($pagesInput) ? array_map('strval', $pagesInput) : [];
        $pages = array_values(array_intersect(
            array_merge([PromoBannerPages::ALL], array_keys(PromoBannerPages::PAGES)),
            $pagesInput
        ));

        if ($name === '' || $headline === '' || $couponCode === '') {
            return [[], 'Name, headline and coupon code are required.'];
        }

        if (!PromoBannerTemplates::isValid($template)) {
            return [[], 'Choose a valid design template.'];
        }

        if ($promotions->findByCode($couponCode, $resellerId) === null) {
            return [[], "No promotion code \"{$couponCode}\" exists — {$missingCodeHint}"];
        }

        if ($startsAt !== null && $expiresAt !== null && $expiresAt < $startsAt) {
            return [[], 'The banner cannot expire before it starts.'];
        }

        if ($pages === [] || in_array(PromoBannerPages::ALL, $pages, true)) {
            $pages = [PromoBannerPages::ALL];
        }

        return [[
            'name' => $name,
            'template' => $template,
            'eyebrow_text' => $eyebrowText !== '' ? $eyebrowText : null,
            'headline' => $headline,
            'subtext' => $subtext !== '' ? $subtext : null,
            'coupon_code' => $couponCode,
            'cta_text' => $ctaText,
            'target_pages' => (string) json_encode($pages, JSON_UNESCAPED_SLASHES),
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
        ], null];
    }

    /** A Y-m-d date or null — anything else is treated as "not set" rather than stored. */
    private static function date(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    /** Keep a value inside its column, so a long paste is trimmed rather than a SQL error. */
    private static function clip(string $value, int $max): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }
}
