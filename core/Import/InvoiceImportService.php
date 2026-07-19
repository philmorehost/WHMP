<?php

declare(strict_types=1);

namespace CodeVault\Import;

use CodeVault\Billing\InvoiceRepository;
use CodeVault\Clients\ClientRepository;
use DateTimeImmutable;

/**
 * Imports historical invoices (R29) onto clients that already exist,
 * matched by email. Uses InvoiceRepository::createHistorical() rather than
 * createFromItems() — an imported invoice's total is a fact from the
 * source system, not something to recompute from line items.
 */
final class InvoiceImportService
{
    /** @var array<string, array<int, string>> */
    private const FIELD_ALIASES = [
        'client_email' => ['client_email', 'email', 'client email'],
        'status' => ['status'],
        'total' => ['total', 'amount'],
        'tax_amount' => ['tax_amount', 'tax', 'tax amount'],
        'due_date' => ['due_date', 'due date'],
        'paid_at' => ['paid_at', 'paid_date', 'paid at', 'paid date'],
    ];

    private const REQUIRED_FIELDS = ['client_email', 'total', 'due_date'];

    private const VALID_STATUSES = ['unpaid', 'paid', 'cancelled', 'refunded'];

    public function __construct(
        private readonly ClientRepository $clients,
        private readonly InvoiceRepository $invoices
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

            if (!is_numeric($fields['total']) || (float) $fields['total'] < 0) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => "Invalid total '{$fields['total']}'."];
                continue;
            }

            $taxAmount = $fields['tax_amount'] ?? '';
            if ($taxAmount !== '' && (!is_numeric($taxAmount) || (float) $taxAmount < 0)) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => "Invalid tax_amount '{$taxAmount}'."];
                continue;
            }

            $dueDate = $this->parseDate($fields['due_date']);

            if ($dueDate === null) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => "Invalid due_date '{$fields['due_date']}'. Expected YYYY-MM-DD."];
                continue;
            }

            $status = strtolower($fields['status'] ?? '');
            if ($status === '') {
                $status = 'unpaid';
            } elseif (!in_array($status, self::VALID_STATUSES, true)) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => "Invalid status '{$fields['status']}'. Expected one of: " . implode(', ', self::VALID_STATUSES) . '.'];
                continue;
            }

            $paidAt = null;
            $rawPaidAt = $fields['paid_at'] ?? '';
            if ($rawPaidAt !== '') {
                $paidAt = $this->parseDate($rawPaidAt);

                if ($paidAt === null) {
                    $skipped++;
                    $errors[] = ['row' => $rowNumber, 'reason' => "Invalid paid_at '{$rawPaidAt}'. Expected YYYY-MM-DD."];
                    continue;
                }

                $paidAt .= ' 00:00:00';
            }

            $this->invoices->createHistorical(
                (int) $client['id'],
                $status,
                (float) $fields['total'],
                $taxAmount !== '' ? (float) $taxAmount : 0.0,
                $dueDate,
                $paidAt
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
