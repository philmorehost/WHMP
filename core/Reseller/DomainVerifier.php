<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

/**
 * Proves that a reseller controls the domain they want their store served on.
 *
 * Without this, "claim a domain" would mean any reseller could type in
 * someone else's hostname — or the platform's own — and have us serve a shop
 * (with our certificates) at it. So a claimed domain is inert until it resolves
 * to proof of control, and `ResellerStoreLocator` refuses to serve a custom
 * domain that has not been verified.
 *
 * Two proofs are accepted, because not every DNS provider makes both easy:
 *
 *   - **TXT** at `_codevault-verify.{domain}` containing `codevault-store-verify={token}`
 *     — preferred: it does not interfere with the domain's A/CNAME records, so
 *     a reseller can verify *before* pointing traffic at us.
 *   - **CNAME** at `{domain}` pointing at the platform host — proves control
 *     and that the domain is already pointed here.
 *
 * The DNS lookups are injectable closures. That is not test scaffolding for its
 * own sake: verification depends on a third party's DNS, and a test that
 * actually queried live DNS would be testing the internet. Tests hand in fixed
 * answers and assert what we do with them.
 */
final class DomainVerifier
{
    /** @var callable(string): array<int, array<string, mixed>> */
    private $txtLookup;

    /** @var callable(string): array<int, array<string, mixed>> */
    private $cnameLookup;

    private const PREFIX = '_codevault-verify';

    /**
     * @param callable(string): array<int, array<string, mixed>>|null $txtLookup
     * @param callable(string): array<int, array<string, mixed>>|null $cnameLookup
     */
    public function __construct(?callable $txtLookup = null, ?callable $cnameLookup = null)
    {
        $this->txtLookup = $txtLookup ?? static fn (string $host): array => self::records($host, DNS_TXT);
        $this->cnameLookup = $cnameLookup ?? static fn (string $host): array => self::records($host, DNS_CNAME);
    }

    /** A fresh, unguessable token to put in the DNS record. */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** The record name the reseller creates, e.g. `_codevault-verify.shop.example.com`. */
    public function recordName(string $domain): string
    {
        return self::PREFIX . '.' . $domain;
    }

    /** The exact value the TXT record must contain. */
    public function expectedValue(string $token): string
    {
        return 'codevault-store-verify=' . $token;
    }

    /**
     * Checks both proofs and reports what was found, without judging whether
     * that is what the caller wants — the caller decides, and the raw `found`
     * values are surfaced in the admin UI so a mismatch ("the record exists but
     * says something else") is visible instead of a bare "failed".
     *
     * @return array{verified: bool, method: ?string, found: array<int, string>, error: ?string}
     */
    public function verify(string $domain, string $token, ?string $platformHost = null): array
    {
        $domain = ResellerStoreLocator::normaliseHost($domain);

        if ($domain === '' || !str_contains($domain, '.')) {
            return ['verified' => false, 'method' => null, 'found' => [], 'error' => 'Not a domain name.'];
        }

        $expected = $this->expectedValue($token);
        $found = [];

        try {
            foreach (($this->txtLookup)($this->recordName($domain)) as $record) {
                // Providers differ on the key: 'txt' for DNS_TXT, 'entries' for
                // DNS_ANY, so accept either rather than failing on a technicality.
                foreach (self::stringsFrom($record, ['txt', 'entries', 'txtdata']) as $value) {
                    $found[] = $value;

                    if (trim($value) === $expected) {
                        return ['verified' => true, 'method' => 'txt', 'found' => $found, 'error' => null];
                    }
                }
            }
        } catch (\Throwable $e) {
            return ['verified' => false, 'method' => null, 'found' => $found, 'error' => 'DNS lookup failed: ' . $e->getMessage()];
        }

        if ($platformHost !== null && $platformHost !== '') {
            try {
                foreach (($this->cnameLookup)($domain) as $record) {
                    foreach (self::stringsFrom($record, ['target', 'cname']) as $value) {
                        $found[] = $value;

                        if (rtrim(strtolower($value), '.') === rtrim(strtolower($platformHost), '.')) {
                            return ['verified' => true, 'method' => 'cname', 'found' => $found, 'error' => null];
                        }
                    }
                }
            } catch (\Throwable $e) {
                return ['verified' => false, 'method' => null, 'found' => $found, 'error' => 'DNS lookup failed: ' . $e->getMessage()];
            }
        }

        return ['verified' => false, 'method' => null, 'found' => $found, 'error' => null];
    }

    /**
     * @param array<string, mixed> $record
     * @param array<int, string> $keys
     * @return array<int, string>
     */
    private static function stringsFrom(array $record, array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            if (!array_key_exists($key, $record)) {
                continue;
            }

            $raw = $record[$key];

            if (is_array($raw)) {
                foreach ($raw as $piece) {
                    if (is_string($piece) && $piece !== '') {
                        $values[] = $piece;
                    }
                }

                continue;
            }

            if (is_string($raw) && $raw !== '') {
                $values[] = $raw;
            }
        }

        return $values;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function records(string $host, int $type): array
    {
        $records = @dns_get_record($host, $type);

        return is_array($records) ? $records : [];
    }
}
