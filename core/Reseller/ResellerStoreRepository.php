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
 *   - forSlug()        — free store address, i.e. {slug}.{platform address domain}
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

    /**
     * The store's UPLINE: the store its owner registered under, when the owner is
     * a customer of another reseller (a sub-reseller). Null for a store opened by
     * one of the platform's own customers.
     *
     * A sub-reseller buys at the upline's retail prices (ResellerRetailPricing), so
     * this is read on every priced page of a sub-reseller's store.
     *
     * @param array<string, mixed> $store
     * @return array<string, mixed>|null
     */
    public function uplineFor(array $store): ?array
    {
        $storeId = (int) ($store['id'] ?? 0);
        $ownerId = (int) ($store['client_id'] ?? 0);

        if ($storeId <= 0 || $ownerId <= 0) {
            return null;
        }

        return $this->db->selectOne(
            'SELECT u.* FROM clients c JOIN resellers u ON u.id = c.reseller_id
              WHERE c.id = ? AND u.id <> ? LIMIT 1',
            [$ownerId, $storeId]
        );
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
            "SELECT r.*, c.first_name, c.last_name, c.email,
                    (SELECT COUNT(*) FROM clients sc WHERE sc.reseller_id = r.id) AS customer_count,
                    (SELECT COUNT(*) FROM orders so JOIN clients oc ON oc.id = so.client_id
                      WHERE oc.reseller_id = r.id AND so.status = 'pending') AS pending_orders,
                    up.id AS upline_id, up.slug AS upline_slug, up.brand_name AS upline_brand,
                    upc.reseller_id AS upline_upline_id
             FROM resellers r
             JOIN clients c ON c.id = r.client_id
             LEFT JOIN resellers up ON up.id = c.reseller_id AND up.client_id <> r.client_id
             LEFT JOIN clients upc ON upc.id = up.client_id
             ORDER BY r.id DESC"
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

    /**
     * The headline and tagline at the top of the store's home page. Blank is
     * stored as NULL, which the home page reads as "use the default".
     */
    public function saveHomepage(int $id, ?string $headline, ?string $tagline): void
    {
        $this->db->update(
            'UPDATE resellers SET home_headline = ?, home_tagline = ?, updated_at = ? WHERE id = ?',
            [
                $this->nullIfBlank($headline),
                $this->nullIfBlank($tagline),
                (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                $id,
            ]
        );
    }

    /**
     * The store's "Sign in with Google" settings. A null $sealedSecret keeps the saved
     * secret (the form never shows it back, so a blank field means "unchanged");
     * switching Google off keeps the credentials, so switching it back on is one click.
     */
    public function saveGoogle(int $id, bool $enabled, ?string $clientId, ?string $sealedSecret): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($sealedSecret === null) {
            $this->db->update(
                'UPDATE resellers SET google_enabled = ?, google_client_id = ?, updated_at = ? WHERE id = ?',
                [$enabled ? 1 : 0, $this->nullIfBlank($clientId), $now, $id]
            );

            return;
        }

        $this->db->update(
            'UPDATE resellers SET google_enabled = ?, google_client_id = ?, google_client_secret = ?, updated_at = ? WHERE id = ?',
            [$enabled ? 1 : 0, $this->nullIfBlank($clientId), $sealedSecret, $now, $id]
        );
    }

    /** Forgets the store's Google credentials entirely (and switches Google off). */
    public function clearGoogle(int $id): void
    {
        $this->db->update(
            'UPDATE resellers SET google_enabled = 0, google_client_id = NULL, google_client_secret = NULL, updated_at = ? WHERE id = ?',
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    /**
     * The store's own support chat. Empty strings are stored as NULL so "not set"
     * is one value rather than two — the difference between '' and NULL would
     * otherwise have to be handled at every read, including in the widget partial
     * that decides whether to render anything at all.
     *
     * @param array<string, mixed> $chat already normalised by ResellerStoreService
     */
    public function saveChat(int $id, array $chat): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        // chat_configured_at records that the reseller has now CHOSEN. From here on
        // the saved values are final — blank means "no chat", and the automatic
        // fallback to the owner's account phone (ResellerChat::whatsappDigitsFor)
        // stops applying.
        $this->db->update(
            'UPDATE resellers SET support_whatsapp = ?, tawk_property_id = ?, tawk_widget_id = ?,
                chat_configured_at = ?, updated_at = ? WHERE id = ?',
            [
                $this->nullIfBlank($chat['support_whatsapp'] ?? null),
                $this->nullIfBlank($chat['tawk_property_id'] ?? null),
                $this->nullIfBlank($chat['tawk_widget_id'] ?? null),
                $now,
                $now,
                $id,
            ]
        );
    }

    /**
     * The address a store's customers are written to FROM.
     *
     * The alignment result is CLEARED here, and that is the important half: the check
     * belongs to a DOMAIN, so an address somebody just typed has not been checked at all.
     * Leaving a previous 'aligned' in place would show a pass for a domain nobody looked
     * at — and it is the pass that decides whether the portal warns. Absent is honest;
     * stale is not.
     *
     * Empty string and NULL both store NULL, so a cleared form field and a never-set one
     * behave identically, as saveChat() does for the same reason.
     */
    public function setSupportEmail(int $id, ?string $email): void
    {
        $this->db->update(
            'UPDATE resellers SET support_email = ?, support_email_status = NULL, '
                . 'support_email_checked_at = NULL, support_email_detail = NULL, updated_at = ? WHERE id = ?',
            [
                $this->nullIfBlank($email),
                (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                $id,
            ]
        );
    }

    /**
     * Record what a DNS check found, and WHEN.
     *
     * The timestamp is written even on a pass, because a pass is not permanent truth:
     * DNS records get edited and domains get re-pointed, and a result with no date on it
     * reads as current forever. `$detail` is what to publish when it failed.
     *
     * @param string      $status 'aligned' | 'misaligned' | 'unavailable'
     */
    public function recordMailCheck(int $id, string $status, ?string $detail): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->update(
            'UPDATE resellers SET support_email_status = ?, support_email_checked_at = ?, '
                . 'support_email_detail = ?, updated_at = ? WHERE id = ?',
            [$status, $now, $this->nullIfBlank($detail), $now, $id]
        );
    }

    /**
     * Stores that are on the panel and could be given a mailbox.
     *
     * Every clause is load-bearing:
     *
     *   domain_provisioned_host = custom_domain   the panel actually serves this domain, so
     *                                             a mailbox can exist on it at all. A store
     *                                             mid-change has a NEW name claimed while
     *                                             the panel still hosts the old one.
     *   support_email IS NULL                     the reseller has not chosen an address.
     *                                             Adopting one for them would override a
     *                                             decision they already made.
     *   mailbox_host <> custom_domain             we have not already made one for THIS
     *                                             domain — without this the job would ask
     *                                             the panel about the same store every day
     *                                             and get "already exists" back forever.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mailboxCandidates(int $limit = 200): array
    {
        return $this->db->select(
            "SELECT * FROM resellers
             WHERE custom_domain IS NOT NULL
               AND domain_provisioned_host IS NOT NULL
               AND domain_provisioned_host = custom_domain
               AND support_email IS NULL
               AND (mailbox_host IS NULL OR mailbox_host <> custom_domain)
             ORDER BY id ASC
             LIMIT " . max(1, $limit)
        );
    }

    /**
     * The mailbox now exists on the panel for this host.
     *
     * Clearing the previous error is the point: a store that failed yesterday and succeeded
     * today must not keep showing yesterday's reason to its owner.
     */
    public function markMailboxProvisioned(int $id, string $host): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->update(
            'UPDATE resellers SET mailbox_host = ?, mailbox_provisioned_at = ?, '
                . 'mailbox_provision_error = NULL, updated_at = ? WHERE id = ?',
            [$host, $now, $now, $id]
        );
    }

    /**
     * The panel refused, or could not be reached.
     *
     * mailbox_host is deliberately NOT written, so the job retries and a panel that is fixed
     * this afternoon works tonight. The message is stored rather than only logged because it
     * is the reseller's explanation, not just ours.
     */
    public function recordMailboxError(int $id, string $message): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->update(
            'UPDATE resellers SET mailbox_provision_error = ?, updated_at = ? WHERE id = ?',
            [$this->nullIfBlank($message), $now, $id]
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
     * The store that owns this support address, if any.
     *
     * Used by mail piping. A message delivered TO a store's support address belongs to that
     * store, not to us: the store's own customer must reach the store's desk rather than
     * our queue, and the store must be able to see it. Without this the ticket would have no
     * client to derive an owner from and would land on the platform — the isolation failure
     * this whole programme exists to avoid.
     *
     * Compared case-INSENSITIVELY with LOWER() on the column, which gives up the index. That
     * is deliberate: the domain part of an address is case-insensitive by definition, so a
     * case-sensitive compare would silently fail to match a message addressed to
     * Support@Shop.example against a stored support@shop.example, and the symptom would be a
     * ticket in the wrong queue rather than an error. `resellers` is a small table.
     */
    public function forSupportEmail(string $email): ?array
    {
        $email = strtolower(trim($email));

        if ($email === '') {
            return null;
        }

        return $this->db->selectOne(
            'SELECT * FROM resellers WHERE LOWER(support_email) = ? LIMIT 1',
            [$email]
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
        //
        // `domain_provisioned_host` / `domain_provisioned_at` are deliberately
        // NOT cleared. They describe what is ON THE HOSTING PANEL, not what the
        // store is allowed to be served on, and this is the one moment the two
        // facts come apart. Wiping them here would destroy the only record that
        // the previous domain needs taking back off the panel — see migration
        // 0199. Removal is driven by the difference, and ResellerDomainSync is
        // what acts on it.
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
     *
     * The panel record is NOT cleared here either, for the same reason as in
     * setCustomDomain(): the refusal is a decision, and the hostname still sitting
     * on the hosting panel is an outstanding action. Clearing it would make an
     * approved-then-refused domain permanently un-removable. ResellerDomainSync
     * takes it off.
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
                updated_at = ?
             WHERE id = ? AND domain_status = 'pending'",
            [$now, $adminId, $reason, $now, $id]
        ) > 0;
    }

    /**
     * The hosting panel accepted this hostname — record which one, and when, and
     * clear any old error.
     *
     * The host is stored rather than assumed to be `custom_domain`, because the
     * two are allowed to differ (that difference is what drives removal). Written
     * together with the timestamp: a host with no timestamp, or a timestamp with
     * no host, cannot be reconciled.
     */
    public function markDomainProvisioned(int $id, string $host): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->update(
            'UPDATE resellers SET domain_provisioned_host = ?, domain_provisioned_at = ?, '
                . 'domain_provision_error = NULL, updated_at = ? WHERE id = ?',
            [$host, $now, $now, $id]
        );
    }

    /**
     * That hostname is no longer on the panel. Both halves go together — a
     * leftover timestamp would claim a host is on the panel when nothing is,
     * which is what the sync logic reads to decide whether to try removing.
     */
    public function markDomainUnprovisioned(int $id): void
    {
        $this->db->update(
            'UPDATE resellers SET domain_provisioned_host = NULL, domain_provisioned_at = NULL, updated_at = ? WHERE id = ?',
            [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    /**
     * Stores with a hostname on the hosting panel that is NOT the one they are
     * approved to be served on — i.e. removals that are still outstanding.
     *
     * This is the visible half of the automation: a removal that failed, or one
     * skipped because provisioning is switched off, has to be findable by an
     * administrator rather than only written into a log line.
     *
     * @return array<int, array<string, mixed>>
     */
    public function outstandingPanelDomains(): array
    {
        return $this->db->select(
            "SELECT r.*, c.first_name, c.last_name, c.email
             FROM resellers r
             JOIN clients c ON c.id = r.client_id
             WHERE r.domain_provisioned_host IS NOT NULL
               AND r.domain_provisioned_host <> ''
               AND (r.custom_domain IS NULL
                    OR r.custom_domain <> r.domain_provisioned_host
                    OR r.domain_status <> 'approved')
             ORDER BY r.id ASC"
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
