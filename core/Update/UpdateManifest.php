<?php

declare(strict_types=1);

namespace CodeVault\Update;

use RuntimeException;

/**
 * The `manifest.json` shipped inside every update ZIP (blueprint §5,
 * ported from the DGV `update-guide.md` design): what version this update
 * brings, which files to delete (ZIP extraction can only add/overwrite,
 * never delete — this list is how "GitHub-style" file removal works), and
 * which DB migrations to run.
 */
final class UpdateManifest
{
    /**
     * @param array<int, string> $filesToDelete relative paths, staging-root-relative
     * @param array<int, string> $databaseQueries raw SQL statements to run in order
     */
    public function __construct(
        public readonly string $version,
        public readonly array $filesToDelete,
        public readonly array $databaseQueries,
        public readonly string $changelog = ''
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException("Manifest not found at [{$path}].");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded) || !isset($decoded['version'])) {
            throw new RuntimeException('Manifest is malformed — missing "version".');
        }

        return new self(
            (string) $decoded['version'],
            array_map('strval', $decoded['files_to_delete'] ?? []),
            array_map('strval', $decoded['database_queries'] ?? []),
            (string) ($decoded['changelog'] ?? ''),
        );
    }
}
