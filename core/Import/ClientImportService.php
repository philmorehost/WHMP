<?php

declare(strict_types=1);

namespace CodeVault\Import;

use CodeVault\Clients\ClientRepository;

/**
 * Maps parsed CSV rows onto ClientRepository::create() by header name
 * (case-insensitive, with a few common aliases) rather than requiring a
 * fixed column order — a real-world exported client list from another
 * system rarely matches this app's exact field names or order.
 *
 * Imported clients get no chosen password (ClientRepository::create()
 * falls back to a random one when none is given) — they're expected to
 * use "forgot password" (blueprint R14) to set one, the same as any
 * client whose account existed before they ever logged in themselves.
 */
final class ClientImportService
{
    /** @var array<string, array<int, string>> */
    private const FIELD_ALIASES = [
        'email' => ['email', 'e-mail', 'email address'],
        'first_name' => ['first_name', 'firstname', 'first name'],
        'last_name' => ['last_name', 'lastname', 'last name'],
        'company_name' => ['company_name', 'company', 'companyname', 'company name'],
        'phone' => ['phone', 'phone_number', 'phonenumber', 'telephone'],
        'address1' => ['address1', 'address', 'address_1', 'address line 1'],
        'address2' => ['address2', 'address_2', 'address line 2'],
        'city' => ['city'],
        'state' => ['state', 'province', 'state/province'],
        'postcode' => ['postcode', 'zip', 'zipcode', 'zip code', 'postal_code', 'postal code'],
        'country' => ['country'],
    ];

    private const REQUIRED_FIELDS = ['email', 'first_name', 'last_name'];

    public function __construct(
        private readonly ClientRepository $clients
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
        $seenInThisBatch = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // +1 for 0-index, +1 for the header row itself

            $fields = [];

            foreach ($columnMap as $field => $columnIndex) {
                $fields[$field] = trim($row[$columnIndex] ?? '');
            }

            $email = $fields['email'];

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => "Invalid or missing email ('{$email}')."];
                continue;
            }

            if ($fields['first_name'] === '' || $fields['last_name'] === '') {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => 'Missing first or last name.'];
                continue;
            }

            $normalizedEmail = strtolower($email);

            if (isset($seenInThisBatch[$normalizedEmail])) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => "Duplicate email within this file ('{$email}')."];
                continue;
            }

            if ($this->clients->findByEmail($email) !== null) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => "An account with this email already exists ('{$email}')."];
                continue;
            }

            $seenInThisBatch[$normalizedEmail] = true;

            $this->clients->create([
                'email' => $email,
                'first_name' => $fields['first_name'],
                'last_name' => $fields['last_name'],
                'company_name' => $fields['company_name'] ?? '',
                'address1' => $fields['address1'] ?? '',
                'address2' => $fields['address2'] ?? '',
                'city' => $fields['city'] ?? '',
                'state' => $fields['state'] ?? '',
                'postcode' => $fields['postcode'] ?? '',
                'country' => $fields['country'] ?? '',
                'phone' => $fields['phone'] ?? '',
            ]);

            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @param array<int, string> $headers
     * @return array<string, int> field name => CSV column index
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
