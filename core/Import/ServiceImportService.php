<?php

declare(strict_types=1);

namespace CodeVault\Import;

use CodeVault\Billing\ServiceRepository;
use CodeVault\Catalog\ProductRepository;
use CodeVault\Clients\ClientRepository;
use DateTimeImmutable;

/**
 * Extends R16's CSV import engine (ClientImportService was the first, and
 * only, importer until now) to services — imports recurring services onto
 * clients that already exist, matched by email, the same way
 * ClientImportService matches on email for dedupe. Unlike a fresh client
 * import, a service needs a real product_id: `product_name` is resolved
 * case-insensitively against the real product catalog
 * (ProductRepository::findByName(), R29), so the CSV can name products in
 * plain text rather than requiring the operator to know internal IDs.
 */
final class ServiceImportService
{
    /** @var array<string, array<int, string>> */
    private const FIELD_ALIASES = [
        'client_email' => ['client_email', 'email', 'client email'],
        'product_name' => ['product_name', 'product', 'product name'],
        'billing_cycle' => ['billing_cycle', 'cycle', 'billing cycle'],
        'amount' => ['amount', 'price', 'recurring_amount', 'recurring amount'],
        'status' => ['status'],
        'next_due_date' => ['next_due_date', 'due_date', 'next due date', 'due date'],
    ];

    private const REQUIRED_FIELDS = ['client_email', 'product_name', 'billing_cycle', 'amount', 'next_due_date'];

    private const VALID_BILLING_CYCLES = ['one_time', 'monthly', 'quarterly', 'semi_annually', 'annually', 'biennially', 'triennially'];

    private const VALID_STATUSES = ['pending', 'active', 'suspended', 'cancelled', 'terminated'];

    public function __construct(
        private readonly ClientRepository $clients,
        private readonly ProductRepository $products,
        private readonly ServiceRepository $services
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

            $client = $fields['client_email'] !== '' ? $this->clients->findByEmail($fields['client_email']) : null;

            if ($client === null) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => "No client found with email '{$fields['client_email']}'."];
                continue;
            }

            $product = $this->products->findByName($fields['product_name']);

            if ($product === null) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => "No product found named '{$fields['product_name']}'."];
                continue;
            }

            $billingCycle = strtolower($fields['billing_cycle']);

            if (!in_array($billingCycle, self::VALID_BILLING_CYCLES, true)) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => "Invalid billing_cycle '{$fields['billing_cycle']}'. Expected one of: " . implode(', ', self::VALID_BILLING_CYCLES) . '.'];
                continue;
            }

            if (!is_numeric($fields['amount']) || (float) $fields['amount'] < 0) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => "Invalid amount '{$fields['amount']}'."];
                continue;
            }

            $dueDate = $this->parseDate($fields['next_due_date']);

            if ($dueDate === null) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => "Invalid next_due_date '{$fields['next_due_date']}'. Expected YYYY-MM-DD."];
                continue;
            }

            $status = strtolower($fields['status'] ?? '');
            if ($status === '') {
                $status = 'pending';
            } elseif (!in_array($status, self::VALID_STATUSES, true)) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => "Invalid status '{$fields['status']}'. Expected one of: " . implode(', ', self::VALID_STATUSES) . '.'];
                continue;
            }

            $this->services->create([
                'client_id' => (int) $client['id'],
                'product_id' => (int) $product['id'],
                'product_name' => $product['name'],
                'billing_cycle' => $billingCycle,
                'amount' => (float) $fields['amount'],
                'status' => $status,
                'next_due_date' => $dueDate,
            ]);

            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    private function parseDate(string $value): ?string
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        // createFromFormat() silently rolls invalid dates like 2023-02-30
        // forward to 2023-03-02 — a round-trip comparison catches that
        // rather than accepting a date the CSV never actually contained.
        return ($date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value) ? $value : null;
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
