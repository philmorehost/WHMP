<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Seo\AiVisibilityScorer;
use CodeVault\Tests\Fixtures\FakePageFetcher;
use PHPUnit\Framework\TestCase;

final class AiVisibilityScorerTest extends TestCase
{
    private FakePageFetcher $fetcher;
    private AiVisibilityScorer $scorer;

    protected function setUp(): void
    {
        $this->fetcher = new FakePageFetcher();
        $this->scorer = new AiVisibilityScorer($this->fetcher);
    }

    private function pageHtml(bool $jsonLd, bool $canonical, ?string $metaDescription, int $h1Count): string
    {
        $head = '<head>';

        if ($canonical) {
            $head .= '<link rel="canonical" href="https://example.test/page">';
        }

        if ($metaDescription !== null) {
            $head .= '<meta name="description" content="' . $metaDescription . '">';
        }

        if ($jsonLd) {
            $head .= '<script type="application/ld+json">{"@type":"Organization"}</script>';
        }

        $head .= '</head><body>';

        for ($i = 0; $i < $h1Count; $i++) {
            $head .= '<h1>Heading</h1>';
        }

        return $head . '</body>';
    }

    public function test_page_with_all_signals_scores_100(): void
    {
        $goodDescription = str_repeat('a', 80);
        $this->fetcher->respondWith(200, $this->pageHtml(true, true, $goodDescription, 1));

        $result = $this->scorer->scoreOne('/kb/1');

        $this->assertSame(100, $result['score']);
        $this->assertNull($result['error']);
        $this->assertTrue($result['checks']['Structured data (JSON-LD)']);
        $this->assertTrue($result['checks']['Canonical tag']);
        $this->assertTrue($result['checks']['Meta description (50-160 chars)']);
        $this->assertTrue($result['checks']['Single H1 heading']);
    }

    public function test_page_missing_everything_scores_zero(): void
    {
        $this->fetcher->respondWith(200, '<head></head><body>plain page</body>');

        $result = $this->scorer->scoreOne('/no-seo');

        $this->assertSame(0, $result['score']);
    }

    public function test_meta_description_too_short_fails_that_check(): void
    {
        $this->fetcher->respondWith(200, $this->pageHtml(true, true, 'Too short', 1));

        $result = $this->scorer->scoreOne('/short-meta');

        $this->assertFalse($result['checks']['Meta description (50-160 chars)']);
    }

    public function test_meta_description_too_long_fails_that_check(): void
    {
        $this->fetcher->respondWith(200, $this->pageHtml(true, true, str_repeat('a', 200), 1));

        $result = $this->scorer->scoreOne('/long-meta');

        $this->assertFalse($result['checks']['Meta description (50-160 chars)']);
    }

    public function test_multiple_h1_tags_fail_the_single_h1_check(): void
    {
        $this->fetcher->respondWith(200, $this->pageHtml(true, true, str_repeat('a', 80), 2));

        $result = $this->scorer->scoreOne('/double-h1');

        $this->assertFalse($result['checks']['Single H1 heading']);
    }

    public function test_zero_h1_tags_fail_the_single_h1_check(): void
    {
        $this->fetcher->respondWith(200, $this->pageHtml(true, true, str_repeat('a', 80), 0));

        $result = $this->scorer->scoreOne('/no-h1');

        $this->assertFalse($result['checks']['Single H1 heading']);
    }

    public function test_non_200_response_scores_zero_with_an_error_message(): void
    {
        $this->fetcher->respondWith(500, 'Internal Server Error');

        $result = $this->scorer->scoreOne('/broken');

        $this->assertSame(0, $result['score']);
        $this->assertNotNull($result['error']);
    }

    public function test_score_all_scores_multiple_paths(): void
    {
        $this->fetcher->respondWith(200, $this->pageHtml(true, true, str_repeat('a', 80), 1));

        $results = $this->scorer->scoreAll(['/a', '/b', '/c']);

        $this->assertCount(3, $results);
        $this->assertSame(['/a', '/b', '/c'], array_column($results, 'path'));
        $this->assertSame(['/a', '/b', '/c'], $this->fetcher->fetchedPaths);
    }
}
