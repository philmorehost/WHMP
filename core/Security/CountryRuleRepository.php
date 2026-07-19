<?php

declare(strict_types=1);

namespace CodeVault\Security;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * MaxMind country rules (blueprint §5): every country is Whitelisted / Not
 * Specified / Blacklisted. Only countries an admin has explicitly set get a
 * row — everything else implicitly defaults to "not_specified".
 */
final class CountryRuleRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    public function policyFor(?string $countryCode): string
    {
        if ($countryCode === null) {
            return 'not_specified';
        }

        $row = $this->db->selectOne('SELECT policy FROM security_country_rules WHERE country_code = ?', [strtoupper($countryCode)]);

        return $row['policy'] ?? 'not_specified';
    }

    public function setPolicy(string $countryCode, string $policy): void
    {
        $countryCode = strtoupper($countryCode);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $existing = $this->db->selectOne('SELECT country_code FROM security_country_rules WHERE country_code = ?', [$countryCode]);

        if ($existing === null) {
            $this->db->insert(
                'INSERT INTO security_country_rules (country_code, policy, updated_at) VALUES (?, ?, ?)',
                [$countryCode, $policy, $now]
            );

            return;
        }

        $this->db->update(
            'UPDATE security_country_rules SET policy = ?, updated_at = ? WHERE country_code = ?',
            [$policy, $now, $countryCode]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select('SELECT * FROM security_country_rules ORDER BY country_code');
    }
}
