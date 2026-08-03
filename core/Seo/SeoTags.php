<?php

declare(strict_types=1);

namespace CodeVault\Seo;

use CodeVault\Config;

/**
 * Builds the canonical URL + JSON-LD structured data every public page
 * needs (blueprint §5 "SEO/AI visibility"). Canonical is built from
 * APP_URL, not the request's Host header — a spoofed Host header must
 * never end up in a canonical tag or structured data a crawler trusts.
 */
final class SeoTags
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    public function canonicalUrl(string $path): string
    {
        $base = rtrim((string) $this->config->env('APP_URL', ''), '/');

        return $base . '/' . ltrim($path, '/');
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
            'url' => rtrim((string) $this->config->env('APP_URL', ''), '/'),
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
