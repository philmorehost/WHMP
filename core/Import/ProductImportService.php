<?php

declare(strict_types=1);

namespace CodeVault\Import;

use CodeVault\Catalog\BillingCycle;
use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Catalog\ProductPricingRepository;
use CodeVault\Catalog\ProductRepository;

/**
 * CSV product+pricing importer, following the same FIELD_ALIASES/import()
 * shape as ClientImportService/ServiceImportService. Products are matched
 * case-insensitively by name (ProductRepository::findByName(), the same
 * dedupe lookup ServiceImportService already uses to resolve a product) —
 * an existing product is updated in place rather than duplicated, and only
 * the billing-cycle price columns actually present in the row are written
 * (a partial re-import doesn't wipe pricing for cycles it doesn't mention).
 * Product groups are resolved by name and auto-created if missing, mirroring
 * WhmcsImportService's "create a default group if none exists" behavior.
 */
final class ProductImportService
{
    /** @var array<string, array<int, string>> */
    private const FIELD_ALIASES = [
        'name' => ['name', 'product_name', 'product'],
        'description' => ['description', 'desc'],
        'group' => ['group', 'product_group', 'product_group_name'],
        'status' => ['status'],
        'setup_fee' => ['setup_fee', 'setup fee'],
        'one_time_price' => ['one_time_price', 'one_time', 'onetime_price'],
        'monthly_price' => ['monthly_price', 'monthly'],
        'quarterly_price' => ['quarterly_price', 'quarterly'],
        'semi_annually_price' => ['semi_annually_price', 'semi_annually', 'semiannually_price'],
        'annually_price' => ['annually_price', 'annually', 'yearly_price'],
        'biennially_price' => ['biennially_price', 'biennially'],
        'triennially_price' => ['triennially_price', 'triennially'],
    ];

    private const REQUIRED_FIELDS = ['name', 'group'];

    private const VALID_STATUSES = ['active', 'hidden'];

    /** @var array<string, string> price column => BillingCycle key */
    private const CYCLE_COLUMNS = [
        'one_time_price' => BillingCycle::ONE_TIME,
        'monthly_price' => BillingCycle::MONTHLY,
        'quarterly_price' => BillingCycle::QUARTERLY,
        'semi_annually_price' => BillingCycle::SEMI_ANNUALLY,
        'annually_price' => BillingCycle::ANNUALLY,
        'biennially_price' => BillingCycle::BIENNIALLY,
        'triennially_price' => BillingCycle::TRIENNIALLY,
    ];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductGroupRepository $groups,
        private readonly ProductPricingRepository $pricing
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

            if ($fields['name'] === '') {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => 'Product name is required.'];
                continue;
            }

            if ($fields['group'] === '') {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => 'Product group is required.'];
                continue;
            }

            $status = strtolower($fields['status'] ?? '');
            if ($status === '') {
                $status = 'active';
            } elseif (!in_array($status, self::VALID_STATUSES, true)) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => "Invalid status '{$fields['status']}'. Expected one of: " . implode(', ', self::VALID_STATUSES) . '.'];
                continue;
            }

            $setupFee = ($fields['setup_fee'] ?? '') !== '' ? $fields['setup_fee'] : '0';
            if (!is_numeric($setupFee)) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => "Invalid setup_fee '{$fields['setup_fee']}'."];
                continue;
            }

            $cyclePrices = [];
            $invalidPrice = null;
            foreach (self::CYCLE_COLUMNS as $column => $cycle) {
                $value = $fields[$column] ?? '';
                if ($value === '') {
                    continue;
                }
                if (!is_numeric($value) || (float) $value < 0) {
                    $invalidPrice = "Invalid {$column} '{$value}'.";
                    break;
                }
                $cyclePrices[$cycle] = (float) $value;
            }

            if ($invalidPrice !== null) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => $invalidPrice];
                continue;
            }

            $group = $this->groups->findByName($fields['group']);
            $groupId = $group !== null ? (int) $group['id'] : $this->groups->create($fields['group'], null);

            $existing = $this->products->findByName($fields['name']);
            $productFields = [
                'product_group_id' => $groupId,
                'name' => $fields['name'],
                'description' => ($fields['description'] ?? '') !== '' ? $fields['description'] : null,
                'status' => $status,
            ];

            $productId = $existing !== null ? (int) $existing['id'] : null;
            if ($productId !== null) {
                $this->products->update($productId, $productFields);
            } else {
                $productId = $this->products->create($productFields);
            }

            foreach ($cyclePrices as $cycle => $price) {
                $this->pricing->setPricing($productId, $cycle, (float) $setupFee, $price);
            }

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
