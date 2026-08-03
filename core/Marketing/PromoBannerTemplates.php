<?php

declare(strict_types=1);

namespace CodeVault\Marketing;

/**
 * Curated visual designs for promo banners — pure CSS gradients + an emoji
 * icon, no generated/uploaded images. The AI copilot (PromoBannerCopilotController)
 * only ever writes the *copy*; picking one of these is how the admin gets a
 * "designed" look without the platform needing an image-generation provider.
 */
final class PromoBannerTemplates
{
    /** @var array<string, array{label: string, icon: string, panelGradient: string, ctaColor: string, ctaTextColor: string}> */
    public const TEMPLATES = [
        'sunset' => [
            'label' => 'Sunset Pink',
            'icon' => '💗',
            'panelGradient' => 'linear-gradient(160deg, #ec1e63 0%, #ad1457 100%)',
            'ctaColor' => '#ff7a1a',
            'ctaTextColor' => '#ffffff',
        ],
        'ocean' => [
            'label' => 'Ocean Blue',
            'icon' => '🌊',
            'panelGradient' => 'linear-gradient(160deg, #2563eb 0%, #1e3a8a 100%)',
            'ctaColor' => '#0ea5e9',
            'ctaTextColor' => '#ffffff',
        ],
        'forest' => [
            'label' => 'Forest Green',
            'icon' => '🌿',
            'panelGradient' => 'linear-gradient(160deg, #16a34a 0%, #14532d 100%)',
            'ctaColor' => '#f59e0b',
            'ctaTextColor' => '#1a1a1a',
        ],
        'midnight' => [
            'label' => 'Midnight Purple',
            'icon' => '✨',
            'panelGradient' => 'linear-gradient(160deg, #7c3aed 0%, #2e1065 100%)',
            'ctaColor' => '#ec4899',
            'ctaTextColor' => '#ffffff',
        ],
        'sunrise' => [
            'label' => 'Sunrise Orange',
            'icon' => '☀️',
            'panelGradient' => 'linear-gradient(160deg, #f97316 0%, #b45309 100%)',
            'ctaColor' => '#2563eb',
            'ctaTextColor' => '#ffffff',
        ],
    ];

    public const DEFAULT_KEY = 'sunset';

    /** @return array{label: string, icon: string, panelGradient: string, ctaColor: string, ctaTextColor: string} */
    public static function get(string $key): array
    {
        return self::TEMPLATES[$key] ?? self::TEMPLATES[self::DEFAULT_KEY];
    }

    public static function isValid(string $key): bool
    {
        return isset(self::TEMPLATES[$key]);
    }
}
