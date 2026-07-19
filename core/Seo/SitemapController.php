<?php

declare(strict_types=1);

namespace CodeVault\Seo;

use CodeVault\Cache\Cache;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Knowledgebase\KbArticleRepository;
use CodeVault\Request;
use CodeVault\Response;

/**
 * sitemap.xml, generated from live data rather than hand-maintained
 * (blueprint §5 "sitemap automation") — every public, indexable URL:
 * home, store + active products, KB index + articles, downloads, status.
 */
final class SitemapController
{
    public function __construct(
        private readonly SeoTags $seo,
        private readonly ProductRepository $products,
        private readonly KbArticleRepository $articles,
        private readonly Cache $cache
    ) {
    }

    public function index(Request $request): Response
    {
        $xml = $this->cache->remember('sitemap:xml', 300, function () {
            $urls = ['/', '/store', '/kb', '/downloads', '/status'];

            foreach ($this->products->all(includeHidden: false) as $product) {
                $urls[] = "/store/{$product['id']}";
            }

            foreach ($this->articles->all() as $article) {
                $urls[] = "/kb/{$article['id']}";
            }

            $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

            foreach ($urls as $path) {
                $xml .= '  <url><loc>' . htmlspecialchars($this->seo->canonicalUrl($path), ENT_XML1) . '</loc></url>' . "\n";
            }

            return $xml . '</urlset>';
        });

        return (new Response($xml, 200))
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    public function robots(Request $request): Response
    {
        $body = <<<TXT
        User-agent: *
        Disallow: /admin
        Disallow: /client
        Disallow: /login
        Disallow: /cart
        Disallow: /api

        Sitemap: {$this->seo->canonicalUrl('/sitemap.xml')}

        TXT;

        return (new Response($body, 200))
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }
}
