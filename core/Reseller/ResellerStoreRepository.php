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
     * The store-wide markup over our list price — the default retail for every
     * item the reseller has not priced by hand. Clamped by the caller
     * (ResellerRetailPricing::clampMarkup) because that is where the rule and
     * its explanation live.
     */
    public function setMarkup(int $id, float $percent): void
    {
        $this->db->update(
            'UPDATE resellers SET markup_percent = ?, updated_at = ? WHERE id = ?',
            [round($percent, 2), (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
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

        // Submitting a domain is a REQUEST, so it returns the row to 'pending'
        // and clears the previous decision: a reseller correcting a refusal must
        // not keep the old approval (or the old reason). Releasing the domain
        // (null) resets everything.
        $this->db->update(
            'UPDATE resellers SET
                custom_domain = ?,
                domain_verification_token = ?,
                domain_verified_at = NULL,
                domain_verification_method = NULL,
                domain_status = ?,
                domain_requested_at = ?,
                domain_reviewed_at = NULL,
                domain_reviewed_by = NULL,
                domain_review_note = NULL,
                domain_provisioned_at = NULL,
                domain_provision_error = NULL,
                updated_at = ?
             WHERE id = ?',
            [
                $domain,
                $token,
                $domain === null ? 'none' : 'pending',
                $domain === null ? null : $now,
                $now,
                $id,
            ]
        );
    }

    /**
     * Records that the domain is verified, and how we came to know.
     *
     * $method is 'txt' or 'cname' for a DNS proof, 'manual' for an admin
     * override, and null for "verified but the method was not recorded" (rows
     * that predate the column). It is written as given rather than merged, so a
     * domain that was forced through by an admin and later produces a real DNS
     * proof is upgraded to the real one instead of keeping the override mark.
     */
    public function markDomainVerified(int $id, ?string $method = null): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->update(
            'UPDATE resellers SET domain_verified_at = ?, domain_verification_method = ?, updated_at = ? WHERE id = ?',
            [$now, $method, $now, $id]
        );
    }

    /**
     * Takes the verification back off a domain, leaving the claim in place.
     *
     * The domain stays claimed (so it is still reserved to this store) but stops
     * being served, because ResellerStoreLocator serves a custom domain only
     * when domain_verified_at is set. Used when a verification turns out to have
     * been wrong.
     */
    public function clearDomainVerification(int $id): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->update(
            'UPDATE resellers SET domain_verified_at = NULL, domain_verification_method = NULL, updated_at = ? WHERE id = ?',
            [$now, $id]
        );
    }

    /**
     * An admin approves the request.
     *
     * Guarded on `domain_status = 'pending'` inside the UPDATE, so two admins
     * deciding at the same moment cannot both succeed and the second one is
     * reported rather than silently overwriting the first. Approving does not by
     * itself serve the domain — it authorises PROVISIONING (asking the web server
     * to answer for the hostname); `domain_verified_at` still gates serving, and
     * the two are deliberately separate (see migration 0198).
     */
    public function approveDomain(int $id, ?int $adminId, ?string $note = null): bool
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->db->update(
            "UPDATE resellers SET
                domain_status = 'approved',
                domain_reviewed_at = ?,
                domain_reviewed_by = ?,
                domain_review_note = ?,
                domain_provision_error = NULL,
                updated_at = ?
             WHERE id = ? AND domain_status = 'pending'",
            [$now, $adminId, $this->nullIfBlank($note), $now, $id]
        ) > 0;
    }

    /**
     * An admin refuses the request, with a reason the reseller is shown.
     *
     * The verification is CLEARED as well as the status changed. A refusal has to
     * stop the domain being served if it was somehow already serving — otherwise
     * "rejected" would be a label with no effect, and a domain whose DNS proof we
     * no longer stand behind would keep answering.
     *
     * The claim itself is left in place so the reseller can see what was refused
     * and correct it; submitting again returns the row to 'pending'.
     */
    public function rejectDomain(int $id, ?int $adminId, string $reason): bool
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->db->update(
            "UPDATE resellers SET
                domain_status = 'rejected',
                domain_reviewed_at = ?,
                domain_reviewed_by = ?,
                domain_review_note = ?,
                domain_verified_at = NULL,
                domain_verification_method = NULL,
                domain_provisioned_at = NULL,
                updated_at = ?
             WHERE id = ? AND domain_status = 'pending'",
            [$now, $adminId, $reason, $now, $id]
        ) > 0;
    }

    /** The hosting panel accepted the domain — record when, and clear any old error. */
    public function markDomainProvisioned(int $id): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->update(
            'UPDATE resellers SET domain_provisioned_at = ?, domain_provision_error = NULL, updated_at = ? WHERE id = ?',
            [$now, $now, $id]
        );
    }

    /**
     * The panel refused the call (or we never got an answer). Stored next to the
     * approval so "approved, but the server would not take it" is a visible
     * state with an owner rather than a silent failure.
     */
    public function recordDomainProvisionError(int $id, string $error): void
    {
        $this->db->update(
            'UPDATE resellers SET domain_provision_error = ?, updated_at = ? WHERE id = ?',
            [$error, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    /** Requests awaiting an admin's decision, oldest first (the work list). */
    public function pendingDomainRequests(): array
    {
        return $this->db->select(
            "SELECT r.*, c.first_name, c.last_name, c.email
             FROM resellers r
             JOIN clients c ON c.id = r.client_id
             WHERE r.domain_status = 'pending' AND r.custom_domain IS NOT NULL
             ORDER BY r.domain_requested_at ASC, r.id ASC"
        );
    }

    /** Recently decided requests, newest first — the admin's history of decisions. */
    public function decidedDomainRequests(int $limit = 50): array
    {
        return $this->db->select(
            "SELECT r.*, c.first_name, c.last_name, c.email
             FROM resellers r
             JOIN clients c ON c.id = r.client_id
             WHERE r.domain_status IN ('approved', 'rejected') AND r.custom_domain IS NOT NULL
             ORDER BY r.domain_reviewed_at DESC, r.id DESC
             LIMIT " . max(1, $limit)
        );
    }

    /**
     * Stores that have claimed a domain and not yet proved control of it.
     *
     * Deliberately only the UNVERIFIED ones. A store that is already verified is
     * never re-examined, and never un-verified, by the nightly check: a
     * transient DNS failure must not take a live storefront offline. Once a
     * domain has been proven, the proof does not expire.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingDomainClaims(): array
    {
        return $this->db->select(
            'SELECT * FROM resellers
             WHERE custom_domain IS NOT NULL
               AND custom_domain <> ?
               AND domain_verification_token IS NOT NULL
               AND domain_verification_token <> ?
               AND domain_verified_at IS NULL
             ORDER BY id ASC',
            ['', '']
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
