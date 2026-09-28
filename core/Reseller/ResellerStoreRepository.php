<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Reseller stores: the row behind a white-label storefront.
 *
 * A store is created for a client on demand (they name it, we derive a slug),
 * and stays invisible until it has either a slug-derived platform address or a
 * verified custom domain. Two lookups are security-relevant and are separated
 * deliberately:
 *
 *   - forSlug()        — platform subdomain, i.e. {slug}.{platform host}
 *   - forVerifiedDomain() — a custom domain, matched ONLY when we have
 *                           verified it points at us. A domain that was merely
 *                           typed in is never served, which is what stops a
 *                           reseller claiming a hostname they do not control.
 */
final class ResellerStoreRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<string, mixed>|null */
    public function forClient(int $clientId): ?array
    {
        return $this->db->selectOne('SELECT * FROM resellers WHERE client_id = ? LIMIT 1', [$clientId]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM resellers WHERE id = ? LIMIT 1', [$id]);
    }

    /** The platform-subdomain match. Used with a slug already validated by isValidSlug(). */
    public function forSlug(string $slug): ?array
    {
        return $this->db->selectOne('SELECT * FROM resellers WHERE slug = ? LIMIT 1', [$slug]);
    }

    /**
     * The custom-domain match, for a host we have verified. The verified_at
     * test is part of the QUERY and not a caller's responsibility: there is no
     * code path that can accidentally serve an unverified domain.
     *
     * @return array<string, mixed>|null
     */
    public function forVerifiedDomain(string $host): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM resellers WHERE custom_domain = ? AND domain_verified_at IS NOT NULL LIMIT 1',
            [$host]
        );
    }

    /** @return array<int, array<string, mixed>> stores with their client attached, for the admin list */
    public function all(): array
    {
        return $this->db->select(
            'SELECT r.*, c.first_name, c.last_name, c.email
             FROM resellers r
             JOIN clients c ON c.id = r.client_id
             ORDER BY r.id DESC'
        );
    }

    public function existsForClient(int $clientId): bool
    {
        return $this->forClient($clientId) !== null;
    }

    public function create(int $clientId, string $slug, ?string $brandName = null): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO resellers (client_id, slug, status, brand_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$clientId, $slug, 'active', $brandName, $now, $now]
        );
    }

    /**
     * Branding. Empty strings are stored as NULL so "not set" is one value
     * rather than two, and CurrentReseller::brand() can simply skip nulls.
     *
     * @param array<string, mixed> $brand
     */
    public function saveBrand(int $id, array $brand): void
    {
        $this->db->update(
            'UPDATE resellers SET brand_name = ?, logo_url = ?, favicon_url = ?, primary_color = ?, updated_at = ? WHERE id = ?',
            [
                $this->nullIfBlank($brand['brand_name'] ?? null),
                $this->nullIfBlank($brand['logo_url'] ?? null),
                $this->nullIfBlank($brand['favicon_url'] ?? null),
                $this->nullIfBlank($brand['primary_color'] ?? null),
                (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                $id,
            ]
        );
    }

    public function renameSlug(int $id, string $slug): void
    {
        $this->db->update(
            'UPDATE resellers SET slug = ?, updated_at = ? WHERE id = ?',
            [$slug, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    /**
     * Claims a custom domain, unverified, and issues a fresh verification
     * token. Moving to a different domain always clears the verified stamp and
     * re-issues the token — verification applies to a domain, so a new domain
     * has to earn it again. Passing null releases the domain entirely.
     */
    public function setCustomDomain(int $id, ?string $domain, string $token): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->update(
            'UPDATE resellers SET custom_domain = ?, domain_verification_token = ?, domain_verified_at = NULL, updated_at = ? WHERE id = ?',
            [$domain, $token, $now, $id]
        );
    }

    public function markDomainVerified(int $id): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->update(
            'UPDATE resellers SET domain_verified_at = ?, updated_at = ? WHERE id = ?',
            [$now, $now, $id]
        );
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->update(
            'UPDATE resellers SET status = ?, updated_at = ? WHERE id = ?',
            [in_array($status, ['active', 'suspended'], true) ? $status : 'active', (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    /** Excluding $exceptId lets a store keep its own slug/domain while renaming something else. */
    public function slugTaken(string $slug, ?int $exceptId = null): bool
    {
        return $this->db->selectOne(
            'SELECT id FROM resellers WHERE slug = ? AND id <> ? LIMIT 1',
            [$slug, $exceptId ?? 0]
        ) !== null;
    }

    public function domainTaken(string $domain, ?int $exceptId = null): bool
    {
        return $this->db->selectOne(
            'SELECT id FROM resellers WHERE custom_domain = ? AND id <> ? LIMIT 1',
            [$domain, $exceptId ?? 0]
        ) !== null;
    }

    /**
     * A store address label: lowercase letters, digits and hyphens, 3-63 chars,
     * not starting or ending with a hyphen, and not a reserved infrastructure
     * name. Goes into a DNS label, a cookie scope and a URL path segment, so it
     * is kept to the same character set as a hostname label.
     */
    public static function normaliseSlug(string $slug): ?string
    {
        $slug = strtolower(trim($slug));
        $slug = (string) preg_replace('~[^a-z0-9\-]+~', '-', $slug);
        $slug = trim((string) preg_replace('~-+~', '-', $slug), '-');

        if ($slug === '' || strlen($slug) < 3 || strlen($slug) > 63) {
            return null;
        }

        if (preg_match('~^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$~', $slug) !== 1) {
            return null;
        }

        // Names that would collide with platform infrastructure or be
        // confusingly impersonatory if a reseller took one.
        $reserved = [
            'www', 'api', 'admin', 'app', 'mail', 'smtp', 'imap', 'pop', 'ftp', 'cpanel',
            'webmail', 'ns', 'ns1', 'ns2', 'dns', 'cdn', 'static', 'assets', 'store',
            'cart', 'checkout', 'client', 'clients', 'login', 'support', 'billing',
            'status', 'help', 'docs', 'blog', 'test', 'staging', 'dev', 'demo',
        ];

        return in_array($slug, $reserved, true) ? null : $slug;
    }

    /** @param mixed $value */
    private function nullIfBlank($value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
