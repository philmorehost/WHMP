<?php

declare(strict_types=1);

namespace CodeVault\Seo;

use CodeVault\Config;
use CodeVault\Reseller\CurrentReseller;

/**
 * Builds the canonical URL + JSON-LD structured data every public page
 * needs (blueprint §5 "SEO/AI visibility"). Canonical is built from
 * APP_URL, not the request's Host header — a spoofed Host header must
 * never end up in a canonical tag or structured data a crawler trusts.
 *
 * The one exception is a reseller storefront, which is served on a host of the
 * reseller's own: there the canonical must point at THAT host, or every store
 * would tell search engines it is a duplicate of the platform. The exception
 * stays safe because the host used is not the raw header — it is the host that
 * matched a verified store in the database (CurrentReseller::host()), and an
 * unmatched Host still cannot reach this method.
 */
final class SeoTags
{
    public function __construct(
        private readonly Config $config,
        private readonly ?CurrentReseller $currentReseller = null
    ) {
    }

    public function canonicalUrl(string $path): string
    {
        $base = $this->baseUrl();

        return $base . '/' . ltrim($path, '/');
    }

    /**
     * The site's own base URL: the storefront's host when one is being served,
     * APP_URL otherwise.
     */
    public function baseUrl(): string
    {
        $host = $this->currentReseller?->host();

        if ($host !== null && $host !== '') {
            // Scheme comes from the store's own host matching the APP_URL
            // scheme; we never trust a forwarded header to choose it.
            $scheme = (string) (parse_url((string) $this->config->env('APP_URL', 'http://localhost'), PHP_URL_SCHEME) ?: 'https');

            return $scheme . '://' . $host;
        }

        return rtrim((string) $this->config->env('APP_URL', ''), '/');
    }

    /** @return array<string, mixed> */
    public function organization(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            // The admin's brand, not the platform's own name — this is the
            // organisation name search engines index and display.
            'name' => brand_name(),
            'url' => $this->baseUrl(),
        ];
    }

    /** @return array<string, mixed> */
    public function article(string $headline, string $body, string $datePublished, string $url): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $headline,
            'articleBody' => mb_strimwidth($body, 0, 500, ''),
            'datePublished' => $datePublished,
            'url' => $url,
            'author' => ['@type' => 'Organization', 'name' => (string) $this->config->env('APP_NAME', 'CodeVault')],
        ];
    }

    /** @return array<string, mixed> */
    public function product(string $name, string $description, float $price, string $url, bool $inStock = true): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $name,
            'description' => $description !== '' ? $description : $name,
            'url' => $url,
            'offers' => [
                '@type' => 'Offer',
                'price' => number_format($price, 2, '.', ''),
                'priceCurrency' => 'USD',
                'availability' => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            ],
        ];
    }

    /**
     * @param array<int, array{name: string, url: string}> $crumbs
     * @return array<string, mixed>
     */
    public function breadcrumbList(array $crumbs): array
    {
        $items = [];

        foreach ($crumbs as $position => $crumb) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position + 1,
                'name' => $crumb['name'],
                'item' => $crumb['url'],
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }
}
