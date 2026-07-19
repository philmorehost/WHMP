<?php

declare(strict_types=1);

namespace CodeVault\Seo;

/**
 * The seam between AiVisibilityScorer's HTML-signal checks and however a
 * page's response actually gets fetched — lets the scorer be unit tested
 * against a fake without needing a real request round trip.
 */
interface PageFetcher
{
    /** @return array{status: int, body: string} */
    public function fetch(string $path): array;
}
