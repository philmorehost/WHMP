<?php

declare(strict_types=1);

namespace CodeVault\Theme;

use CodeVault\Billing\CurrencySelection;
use CodeVault\Billing\CurrencyService;
use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Localization\LanguageRepository;
use CodeVault\Localization\LanguageSelection;
use CodeVault\Localization\LocalizationService;
use CodeVault\Request;
use CodeVault\Reseller\StorefrontCatalogue;
use CodeVault\Response;
use CodeVault\Seo\SeoTags;
use CodeVault\Settings\SettingsRepository;
use CodeVault\View;

/**
 * The MAIN website's home page ("/" on the platform's own host) in the premium
 * design (PlatformSite). A store's "/" never reaches this — routes/web.php hands
 * a store to StorefrontHome first.
 *
 * Every price on the page comes from StorefrontCatalogue, which on the platform
 * quotes list prices, so the home page, the /store listing and checkout always
 * agree. The headline, tagline and the trust line under the guarantees are the
 * admin's own words (Admin → Theme → Website design).
 */
final class PlatformHome
{
    public function __construct(
        private readonly View $view,
        private readonly StorefrontCatalogue $catalogue,
        private readonly SettingsRepository $settings,
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
        $client = $this->guard->currentClient();
        $currency = $this->currency->resolveEffective($client, $this->currencySelection->get());
        $t = $this->localization->translationFor(
            $this->localization->resolveEffective($client, $this->languageSelection->get())
        );

        $categories = $this->catalogue->categories();
        $featured = [];

        foreach (array_slice($categories, 0, PlatformSite::FEATURED_GROUPS) as $category) {
            $featured[] = $category + ['plans' => $this->catalogue->plansFor($category['id'], PlatformSite::PLANS_PER_GROUP)];
        }

        $headline = PlatformSite::headlineFor($this->settings->get(PlatformSite::HEADLINE_KEY));
        $tagline = PlatformSite::taglineFor($this->settings->get(PlatformSite::TAGLINE_KEY));

        $content = $this->view->render('pages.home-premium', [
            'headline' => $headline,
            'tagline' => $tagline,
            'trustLine' => PlatformSite::trustFor($this->settings->get(PlatformSite::TRUST_KEY)),
            'categories' => $categories,
            'featured' => $featured,
            'domainPrices' => $this->catalogue->domainPrices(6),
            'tldCount' => $this->catalogue->tldCount(),
            'contact' => PlatformSite::contact($this->settings),
            'currency' => $currency,
            'money' => fn (float $baseAmount): string => $this->currency->format($baseAmount, $currency),
            'client' => $client,
            't' => $t,
        ]);

        return Response::html($this->view->render('layouts.client', [
            'title' => $headline,
            'content' => $content,
            'storefrontHome' => true,
            'platformPremium' => true,
            'canonicalUrl' => $this->seo->canonicalUrl('/'),
            'metaDescription' => mb_strimwidth($tagline, 0, 160, '...'),
            'jsonLd' => [$this->seo->organization()],
            't' => $t,
            'languages' => $this->languages->active(),
            'selectedCurrency' => $currency,
        ]));
    }
}
