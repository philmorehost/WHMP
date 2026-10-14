<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Billing\CurrencySelection;
use CodeVault\Billing\CurrencyService;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Localization\LanguageRepository;
use CodeVault\Localization\LanguageSelection;
use CodeVault\Localization\LocalizationService;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Seo\SeoTags;
use CodeVault\View;

/**
 * A reseller storefront's home page — "/" on the store's own host.
 *
 * It used to redirect to /store, which printed the entire catalogue on one
 * page: every category, every plan, one after another. The home page now does
 * what a hosting company's home page does — hero, domain search, a handful of
 * featured plans in tabs — and the categories and full catalogue live one
 * click away under SERVICES in the menu (/store?group_id=N), never as a long
 * list on the front page.
 *
 * Nothing here is invented. Every number on the page is read from this site's
 * catalogue at this site's prices (StorefrontCatalogue); there are no review
 * scores, years-in-business or customer counts, because a white-label page
 * cannot know them and a made-up figure on someone else's business is worse
 * than none.
 */
final class StorefrontHome
{
    public const DEFAULT_HEADLINE = 'Fast, secure web hosting for every website';

    public const DEFAULT_TAGLINE = 'Launch your website with reliable hosting, a domain name and professional email — '
        . 'ordered online in minutes and managed from one simple client area.';

    /** How many categories the pricing tabs offer, and how many plans each tab shows. */
    public const FEATURED_GROUPS = 4;

    public const PLANS_PER_GROUP = 3;

    public function __construct(
        private readonly View $view,
        private readonly CurrentReseller $tenant,
        private readonly StorefrontCatalogue $catalogue,
        private readonly CurrencyService $currency,
        private readonly CurrencySelection $currencySelection,
        private readonly ClientAuthGuard $guard,
        private readonly LanguageRepository $languages,
        private readonly LocalizationService $localization,
        private readonly LanguageSelection $languageSelection,
        private readonly SeoTags $seo
    ) {
    }

    public function index(Request $request): Response
    {
        $store = $this->tenant->get() ?? [];
        $client = $this->guard->currentClient();
        $currency = $this->currency->resolveEffective($client, $this->currencySelection->get());
        $t = $this->localization->translationFor(
            $this->localization->resolveEffective($client, $this->languageSelection->get())
        );

        $categories = $this->catalogue->categories();
        $featured = [];

        foreach (array_slice($categories, 0, self::FEATURED_GROUPS) as $category) {
            $featured[] = $category + ['plans' => $this->catalogue->plansFor($category['id'], self::PLANS_PER_GROUP)];
        }

        $content = $this->view->render('storefront.home', [
            'headline' => self::headlineFor($store),
            'tagline' => self::taglineFor($store),
            'categories' => $categories,
            'featured' => $featured,
            'domainPrices' => $this->catalogue->domainPrices(6),
            'tldCount' => $this->catalogue->tldCount(),
            'currency' => $currency,
            'money' => fn (float $baseAmount): string => $this->currency->format($baseAmount, $currency),
            't' => $t,
        ]);

        return Response::html($this->view->render('layouts.client', [
            'title' => self::headlineFor($store),
            'content' => $content,
            'storefrontHome' => true,
            'canonicalUrl' => $this->seo->canonicalUrl('/'),
            'metaDescription' => mb_strimwidth(self::taglineFor($store), 0, 160, '...'),
            'jsonLd' => [$this->seo->organization()],
            't' => $t,
            'languages' => $this->languages->active(),
            'selectedCurrency' => $currency,
        ]));
    }

    /** @param array<string, mixed> $store */
    public static function headlineFor(array $store): string
    {
        $headline = trim((string) ($store['home_headline'] ?? ''));

        return $headline !== '' ? $headline : self::DEFAULT_HEADLINE;
    }

    /** @param array<string, mixed> $store */
    public static function taglineFor(array $store): string
    {
        $tagline = trim((string) ($store['home_tagline'] ?? ''));

        return $tagline !== '' ? $tagline : self::DEFAULT_TAGLINE;
    }

    /**
     * A plan's description as a list of feature lines, for the pricing cards.
     *
     * Descriptions are typed into a plain textarea, usually one feature per
     * line, often with a bullet the admin typed themselves ("- 10GB SSD",
     * "• Free SSL", "✓ cPanel"). Those markers are stripped so every card uses
     * the same tick. A description that is one long sentence is not a list, so
     * it returns nothing and the card shows it as a paragraph instead.
     *
     * @return array<int, string>
     */
    public static function features(string $description, int $max = 6): array
    {
        $text = html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />', '</li>', '</p>'], "\n", $description)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = preg_split('/\R/u', $text) ?: [];
        $features = [];

        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/^[\s\-\*\x{2022}\x{2713}\x{2714}\x{25CF}\x{25AA}>]+/u', '', $line));

            if ($line !== '') {
                $features[] = $line;
            }
        }

        if (count($features) < 2) {
            return [];
        }

        return array_slice($features, 0, $max);
    }
}
