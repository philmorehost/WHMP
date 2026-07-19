<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Country(+state) tax rules (blueprint §4.4). A country+state row, when
 * present, overrides the whole-country (state IS NULL) row for that country.
 */
final class TaxRuleRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM tax_rules ORDER BY country_code, state');
    }

    /**
     * @return array<string, mixed>|null the most specific matching rule
     */
    public function resolve(?string $countryCode, ?string $state): ?array
    {
        if ($countryCode === null) {
            return null;
        }

        if ($state !== null) {
            $specific = $this->db->selectOne(
                'SELECT * FROM tax_rules WHERE country_code = ? AND state = ?',
                [strtoupper($countryCode), $state]
            );

            if ($specific !== null) {
                return $specific;
            }
        }

        return $this->db->selectOne(
            'SELECT * FROM tax_rules WHERE country_code = ? AND state IS NULL',
            [strtoupper($countryCode)]
        );
    }

    public function setRate(string $countryCode, ?string $state, string $name, float $rate): void
    {
        $existing = $this->resolveExact($countryCode, $state);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($existing === null) {
            $this->db->insert(
                'INSERT INTO tax_rules (country_code, state, name, rate, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                [strtoupper($countryCode), $state, $name, $rate, $now, $now]
            );

            return;
        }

        $this->db->update(
            'UPDATE tax_rules SET name = ?, rate = ?, updated_at = ? WHERE id = ?',
            [$name, $rate, $now, $existing['id']]
        );
    }

    public function delete(int $id): void
    {
        $this->db->delete('DELETE FROM tax_rules WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null exact match only, used to decide insert vs update */
    private function resolveExact(string $countryCode, ?string $state): ?array
    {
        if ($state === null) {
            return $this->db->selectOne('SELECT * FROM tax_rules WHERE country_code = ? AND state IS NULL', [strtoupper($countryCode)]);
        }

        return $this->db->selectOne('SELECT * FROM tax_rules WHERE country_code = ? AND state = ?', [strtoupper($countryCode), $state]);
    }
}
