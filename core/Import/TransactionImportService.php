<?php

declare(strict_types=1);

namespace CodeVault\Import;

use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\TransactionRepository;
use DateTimeImmutable;

/**
 * Imports historical payment transactions (R29) against invoices that
 * already exist in this app — either created by hand, by checkout, or by
 * InvoiceImportService moments earlier in the same migration effort.
 * There is no cross-system ID mapping in this app (a legacy system's own
 * invoice numbers mean nothing here), so `invoice_id` in the CSV is
 * expected to be this app's own real, numeric invoice ID — the intended
 * workflow is: import invoices first, note the resulting IDs (the invoice
 * list screen shows them), then reference those same IDs when importing
 * transactions. This is a disclosed constraint, not a hidden assumption.
 */
final class TransactionImportService
{
    /** @var array<string, array<int, string>> */
    private const FIELD_ALIASES = [
        'invoice_id' => ['invoice_id', 'invoice', 'invoice id'],
        'gateway_slug' => ['gateway_slug', 'gateway', 'gateway slug'],
        'amount' => ['amount'],
        'status' => ['status'],
        'gateway_transaction_id' => ['gateway_transaction_id', 'transaction_id', 'gateway transaction id', 'transaction id'],
        'created_at' => ['created_at', 'date', 'created at'],
    ];

    private const REQUIRED_FIELDS = ['invoice_id', 'amount', 'created_at'];

    private const VALID_STATUSES = ['completed', 'refunded', 'failed'];

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly TransactionRepository $transactions
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

            if (!ctype_digit($fields['invoice_id'])) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => "Invalid invoice_id '{$fields['invoice_id']}'."];
                continue;
            }

            $invoice = $this->invoices->find((int) $fields['invoice_id']);

            if ($invoice === null) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => "No invoice found with ID {$fields['invoice_id']}."];
                continue;
            }

            if (!is_numeric($fields['amount']) || (float) $fields['amount'] < 0) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => "Invalid amount '{$fields['amount']}'."];
                continue;
            }

            $createdAt = $this->parseDate($fields['created_at']);

            if ($createdAt === null) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => "Invalid created_at '{$fields['created_at']}'. Expected YYYY-MM-DD."];
                continue;
            }

            $status = strtolower($fields['status'] ?? '');
            if ($status === '') {
                $status = 'completed';
            } elseif (!in_array($status, self::VALID_STATUSES, true)) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => "Invalid status '{$fields['status']}'. Expected one of: " . implode(', ', self::VALID_STATUSES) . '.'];
                continue;
            }

            $gatewaySlug = ($fields['gateway_slug'] ?? '') !== '' ? $fields['gateway_slug'] : 'manual';
            $gatewayTransactionId = ($fields['gateway_transaction_id'] ?? '') !== '' ? $fields['gateway_transaction_id'] : null;

            $this->transactions->createHistorical(
                (int) $fields['invoice_id'],
                $gatewaySlug,
                (float) $fields['amount'],
                $status,
                $gatewayTransactionId,
                $createdAt . ' 00:00:00'
            );

            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    private function parseDate(string $value): ?string
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

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
