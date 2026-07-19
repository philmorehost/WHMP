<?php

declare(strict_types=1);

namespace CodeVault\Modules;

/**
 * Custom reports surfaced under Admin → Reports (blueprint §4.3).
 */
interface ReportModule extends Module
{
    /** @return array{columns: array<int, string>, rows: array<int, array<int, mixed>>} */
    public function generate(array $filters): array;

    /**
     * @return array<string, array{type: string, label: string}> filter
     *   fields shown above the report (date range, product, etc.)
     */
    public function filters(): array;
}
