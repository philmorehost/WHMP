<?php

declare(strict_types=1);

namespace CodeVault\Seo;

/**
 * Generic best-practice AI-search-visibility scorer (blueprint §5) — no
 * proprietary rubric is defined anywhere in this codebase, so this checks
 * the standard signals crawlers and AI answer-engines actually look for:
 * structured data, a canonical tag, a well-sized meta description, and a
 * single H1. Each page is fetched live (not guessed from code paths) so
 * the score reflects what a crawler would actually see.
 */
final class AiVisibilityScorer
{
    private const CHECK_COUNT = 4;

    public function __construct(
        private readonly PageFetcher $fetcher
    ) {
    }

    /**
     * @param array<int, string> $paths
     * @return array<int, array{path: string, score: int, checks: array<string, bool>, error: ?string}>
     */
    public function scoreAll(array $paths): array
    {
        return array_map(fn (string $path) => $this->scoreOne($path), $paths);
    }

    /** @return array{path: string, score: int, checks: array<string, bool>, error: ?string} */
    public function scoreOne(string $path): array
    {
        $response = $this->fetcher->fetch($path);

        if ($response['status'] !== 200) {
            return [
                'path' => $path,
                'score' => 0,
                'checks' => $this->emptyChecks(),
                'error' => "Fetch failed (HTTP {$response['status']}).",
            ];
        }

        $html = $response['body'];

        $checks = [
            'Structured data (JSON-LD)' => (bool) preg_match('/<script[^>]+type=["\']application\/ld\+json["\']/i', $html),
            'Canonical tag' => (bool) preg_match('/<link[^>]+rel=["\']canonical["\']/i', $html),
            'Meta description (50-160 chars)' => $this->hasGoodMetaDescription($html),
            'Single H1 heading' => $this->hasSingleH1($html),
        ];

        $score = (int) round((count(array_filter($checks)) / self::CHECK_COUNT) * 100);

        return ['path' => $path, 'score' => $score, 'checks' => $checks, 'error' => null];
    }

    /** @return array<string, bool> */
    private function emptyChecks(): array
    {
        return [
            'Structured data (JSON-LD)' => false,
            'Canonical tag' => false,
            'Meta description (50-160 chars)' => false,
            'Single H1 heading' => false,
        ];
    }

    private function hasGoodMetaDescription(string $html): bool
    {
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $matches) !== 1) {
            return false;
        }

        $length = strlen($matches[1]);

        return $length >= 50 && $length <= 160;
    }

    private function hasSingleH1(string $html): bool
    {
        return preg_match_all('/<h1[\s>]/i', $html) === 1;
    }
}
