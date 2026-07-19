<?php

declare(strict_types=1);

namespace CodeVault\Import;

use CodeVault\Domains\DomainPricingRepository;

/**
 * CSV TLD-pricing importer, following the same FIELD_ALIASES/import() shape
 * as the other CSV importers. Upserts via DomainPricingRepository::save()
 * (already finds-by-TLD then updates or inserts), so re-importing an
 * updated price list is safe to run repeatedly.
 */
final class DomainPricingImportService
{
    /** @var array<string, array<int, string>> */
    private const FIELD_ALIASES = [
        'tld' => ['tld', 'extension', 'domain_extension'],
        'registrar_slug' => ['registrar_slug', 'registrar'],
        'register_price' => ['register_price', 'register', 'registration_price'],
        'transfer_price' => ['transfer_price', 'transfer'],
        'renew_price' => ['renew_price', 'renewal_price', 'renew'],
    ];

    private const REQUIRED_FIELDS = ['tld', 'registrar_slug', 'register_price', 'transfer_price', 'renew_price'];

    public function __construct(
        private readonly DomainPricingRepository $domainPricing
    ) {
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, string>> $rows
     * @return array{imported: int, skipped: int, errors: array<int, array{row: int, reason: string}>}|array{error: string}
     */
    public function import(array $headers, array $rows): array
    {
        $columnMap = $this->mapColumns($headers);
        $missing = array_diff(self::REQUIRED_FIELDS, array_keys($columnMap));

        if ($missing !== []) {
            return ['error' => 'CSV is missing required column(s): ' . implode(', ', $missing) . '. Recognized headers were: ' . (implode(', ', $headers) ?: '(none)') . '.'];
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;

            $fields = [];
            foreach ($columnMap as $field => $columnIndex) {
                $fields[$field] = trim($row[$columnIndex] ?? '');
            }

            if ($fields['tld'] === '') {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => 'TLD is required.'];
                continue;
            }

            if ($fields['registrar_slug'] === '') {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => 'registrar_slug is required.'];
                continue;
            }

            $priceError = null;
            foreach (['register_price', 'transfer_price', 'renew_price'] as $priceField) {
                if (!is_numeric($fields[$priceField]) || (float) $fields[$priceField] < 0) {
                    $priceError = "Invalid {$priceField} '{$fields[$priceField]}'.";
                    break;
                }
            }

            if ($priceError !== null) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => $priceError];
                continue;
            }

            $this->domainPricing->save([
                'tld' => $fields['tld'],
                'registrar_slug' => $fields['registrar_slug'],
                'register_price' => $fields['register_price'],
                'transfer_price' => $fields['transfer_price'],
                'renew_price' => $fields['renew_price'],
            ]);

            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @param array<int, string> $headers
     * @return array<string, int>
     */
    private function mapColumns(array $headers): array
    {
        $normalizedHeaders = array_map(static fn (string $h) => strtolower(trim($h)), $headers);
        $map = [];

        foreach (self::FIELD_ALIASES as $field => $aliases) {
            foreach ($normalizedHeaders as $columnIndex => $header) {
                if (in_array($header, $aliases, true)) {
                    $map[$field] = $columnIndex;
                    break;
                }
            }
        }

        return $map;
    }
}
