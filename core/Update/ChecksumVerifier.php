<?php

declare(strict_types=1);

namespace CodeVault\Update;

/**
 * SHA256 integrity check on a downloaded update ZIP (blueprint §5) —
 * catches both truncated downloads and tampering before anything gets
 * extracted over live files.
 */
final class ChecksumVerifier
{
    public function verify(string $filePath, string $expectedSha256): bool
    {
        if (!is_file($filePath)) {
            return false;
        }

        $actual = hash_file('sha256', $filePath);

        return $actual !== false && hash_equals(strtolower($expectedSha256), strtolower($actual));
    }
}
