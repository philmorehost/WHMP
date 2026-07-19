<?php

declare(strict_types=1);

namespace CodeVault\Import;

/**
 * Pure CSV parsing — no DB, no entity knowledge. One line per record (a
 * quoted field containing an embedded newline is a known, documented
 * scope boundary — real-world client-list exports essentially never have
 * one, and handling it properly needs a stream-based fgetcsv() reader
 * rather than this line-split approach).
 */
final class CsvParser
{
    /** @return array{headers: array<int, string>, rows: array<int, array<int, string>>} */
    public function parse(string $content): array
    {
        // Strip a UTF-8 BOM, which Excel loves to prepend and which would
        // otherwise corrupt the first header's name.
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $line) => trim($line) !== ''));

        if ($lines === []) {
            return ['headers' => [], 'rows' => []];
        }

        $headers = array_map(
            static fn (string $header) => trim($header),
            str_getcsv(array_shift($lines)) ?: []
        );

        $rows = array_map(static fn (string $line) => str_getcsv($line) ?: [], $lines);

        return ['headers' => $headers, 'rows' => $rows];
    }
}
