<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Cache\Cache;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Seo\SeoTags;
use CodeVault\Theme\ThemeSettings;

/**
 * RSS 2.0 feed of published announcements (blueprint §4.1
 * "Announcements (+RSS)"). Served from live data like sitemap.xml rather
 * than a hand-maintained file, so every scheduled/sent announcement is
 * in the feed automatically.
 *
 * Body HTML is stripped to text for <description> — RSS readers should
 * never be handed raw markup they might render unsafely, and the CDATA
 * wrapper would still be a footgun if the body ever contained it.
 */
final class AnnouncementRssController
{
    public function __construct(
        private readonly AnnouncementRepository $announcements,
        private readonly SeoTags $seo,
        private readonly ThemeSettings $theme,
        private readonly Cache $cache
    ) {
    }

    public function index(Request $request): Response
    {
        $xml = $this->cache->remember('announcements:rss', 300, function () {
            $base = rtrim($this->seo->canonicalUrl('/'), '/');
            $now = gmdate('D, d M Y H:i:s') . ' GMT';

            $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
            $xml .= "  <channel>\n";
            $xml .= "    <title>" . htmlspecialchars($this->feedTitle(), ENT_XML1) . "</title>\n";
            $xml .= "    <link>{$base}/announcements</link>\n";
            $xml .= "    <description>" . htmlspecialchars('News and announcements from ' . $this->feedTitle(), ENT_XML1) . "</description>\n";
            $xml .= "    <language>en</language>\n";
            $xml .= "    <lastBuildDate>{$now}</lastBuildDate>\n";
            $xml .= "    <atom:link href=\"{$base}/announcements.rss\" rel=\"self\" type=\"application/rss+xml\" />\n";

            foreach ($this->announcements->published(20) as $item) {
                $link = "{$base}/announcements/{$item['id']}";
                $description = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $item['body'])));

                $xml .= "    <item>\n";
                $xml .= "      <title>" . htmlspecialchars((string) $item['title'], ENT_XML1) . "</title>\n";
                $xml .= "      <link>" . htmlspecialchars($link, ENT_XML1) . "</link>\n";
                $xml .= "      <guid isPermaLink=\"true\">" . htmlspecialchars($link, ENT_XML1) . "</guid>\n";
                $xml .= '      <pubDate>' . gmdate('D, d M Y H:i:s', strtotime((string) $item['published_at'])) . " GMT</pubDate>\n";
                $xml .= "      <description>" . htmlspecialchars(mb_substr($description, 0, 500), ENT_XML1) . "</description>\n";
                $xml .= "    </item>\n";
            }

            return $xml . "  </channel>\n</rss>\n";
        });

        return (new Response($xml, 200))
            ->withHeader('Content-Type', 'application/rss+xml; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    private function feedTitle(): string
    {
        return $this->theme->get()['brandName'] ?? 'System Announcements';
    }
}
