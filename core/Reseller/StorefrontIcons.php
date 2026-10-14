<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

/**
 * The storefront's icon set: small inline SVGs drawn with currentColor, so
 * every icon takes the store's own brand colour from CSS and nothing here can
 * leak the platform's palette (no fills, no hex — see StorefrontLayoutTest).
 *
 * Inline rather than an icon font or sprite: no extra request, no CSP entry,
 * and they render in the storefront's first paint.
 */
final class StorefrontIcons
{
    /** @var array<string, string> path data, 24x24 viewBox, stroked */
    private const PATHS = [
        'server' => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01M11 7.5h6M11 16.5h6"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/>',
        'cloud' => '<path d="M7 18a5 5 0 1 1 .9-9.9A6 6 0 0 1 19.5 10 4 4 0 0 1 18 18H7z"/>',
        'shield' => '<path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6l-8-3z"/><path d="m9 12 2 2 4-4"/>',
        'cart' => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6.2"/>',
        'box' => '<path d="M21 8 12 3 3 8v8l9 5 9-5V8z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
        'code' => '<path d="m8 8-4 4 4 4M16 8l4 4-4 4M14 5l-4 14"/>',
        'bolt' => '<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"/>',
        'lock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'panel' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M8 4v16"/>',
        'headset' => '<path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="3" y="14" width="4" height="6" rx="1.5"/><rect x="17" y="14" width="4" height="6" rx="1.5"/><path d="M19 20a3 3 0 0 1-3 2h-3"/>',
        'trend' => '<path d="m3 17 6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        'wallet' => '<rect x="3" y="6" width="18" height="14" rx="2"/><path d="M3 10h18M16 15h2"/>',
        'check' => '<path d="m5 12 5 5 9-10"/>',
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'phone' => '<path d="M5 3h4l2 5-2.5 1.5a11 11 0 0 0 6 6L16 13l5 2v4a2 2 0 0 1-2 2A17 17 0 0 1 3 5a2 2 0 0 1 2-2z"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'transfer' => '<path d="M4 8h14l-3-3M20 16H6l3 3"/>',
        'tag' => '<path d="M3 12V4h8l10 10-8 8L3 12z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'ticket' => '<path d="M4 6h16v4a2 2 0 0 0 0 4v4H4v-4a2 2 0 0 0 0-4V6z"/><path d="M13 6v12"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'rocket' => '<path d="M5 15c-1.5 1.5-2 5-2 5s3.5-.5 5-2"/><path d="M9 15 6 12c1-4 4.5-8 12-9-1 7.5-5 11-9 12z"/><circle cx="14" cy="9" r="1.5"/>',
    ];

    /** The SVG for a named icon; unknown names fall back to the generic box. */
    public static function svg(string $name, string $class = 'sf-icon'): string
    {
        $paths = self::PATHS[$name] ?? self::PATHS['box'];

        return '<svg class="' . htmlspecialchars($class, ENT_QUOTES) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . $paths . '</svg>';
    }

    /**
     * An icon for a product category, chosen by what the category SELLS (its
     * name), never by a vendor's name.
     */
    public static function forCategory(string $name): string
    {
        $lower = strtolower($name);

        $rules = [
            'globe' => ['domain'],
            'mail' => ['email', 'mail', 'workspace'],
            'shield' => ['ssl', 'security', 'certificate'],
            'cloud' => ['vps', 'cloud', 'virtual'],
            'server' => ['dedicated', 'server', 'hosting', 'reseller'],
            'code' => ['wordpress', 'developer', 'app', 'web design', 'website'],
            'cart' => ['ecommerce', 'e-commerce', 'shop', 'store'],
        ];

        foreach ($rules as $icon => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($lower, $needle)) {
                    return $icon;
                }
            }
        }

        return 'box';
    }
}
