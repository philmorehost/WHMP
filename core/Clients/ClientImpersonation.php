<?php

declare(strict_types=1);

namespace CodeVault\Clients;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Config;
use CodeVault\Database;
use CodeVault\Reseller\CurrentReseller;
use CodeVault\Reseller\ResellerStoreLocator;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Session\SessionManager;
use DateTimeImmutable;

/**
 * Signing in to a customer's account on somebody else's behalf, on the website the
 * customer actually uses: a reseller for one of its own customers, or a platform admin
 * for a store's customer.
 *
 * WHY A TICKET, NOT A SESSION SWITCH
 *
 * A store's customer signs in on the store's website (ClientSiteAccess) — its own
 * subdomain or custom domain — and session cookies never cross hosts. The reseller and
 * the admin are signed in on the platform's host. So the platform issues a one-time
 * ticket to the authenticated reseller/admin and sends the browser to the store's site
 * with it; the store's site redeems it into a session there. The reseller's or admin's
 * own session on the platform is untouched, so "return" just goes back to it.
 *
 * WHAT MAKES A TICKET SAFE TO HAND TO A BROWSER
 *
 *  - It is 256 random bits, and only its SHA-256 is stored.
 *  - It is redeemed ONCE: the UPDATE that consumes it is the check, so two tabs racing
 *    with the same link get one session between them.
 *  - It expires two minutes after issue.
 *  - It works only on the one site it names, and only while the customer still belongs
 *    to that site — a customer moved to another store since cannot be reached with an
 *    old link.
 *  - Who it was issued to and where to return are stored with it, server-side; the
 *    request carries nothing but the token.
 *
 * WHAT AN IMPERSONATING RESELLER MAY NOT DO
 *
 * Everything the customer can do, except what would take the account from them: their
 * password, security PIN, two-factor and security question, their saved cards, their
 * login email (the profile form), privacy requests, and the customer's own reseller
 * area if they have one (restrictedForReseller()). A platform admin is not restricted,
 * as with the existing "Login as Client".
 */
final class ClientImpersonation
{
    public const SESSION_KEY = 'impersonation';
    public const TTL_SECONDS = 120;

    public function __construct(
        private readonly Database $db,
        private readonly SessionManager $session,
        private readonly ClientRepository $clients,
        private readonly CurrentReseller $current,
        private readonly ResellerStoreLocator $locator,
        private readonly ResellerStoreRepository $stores,
        private readonly Config $config,
        private readonly ActivityLogger $activity
    ) {
    }

    /**
     * Issue a ticket and return the URL that redeems it, on the customer's own site.
     *
     * @param array<string, mixed> $client
     * @param 'admin'|'reseller' $actorType
     * @return array{success: bool, url: ?string, error: ?string}
     */
    public function issue(array $client, string $actorType, int $actorId, string $actorLabel, string $returnUrl, ?string $ip = null): array
    {
        $clientId = (int) ($client['id'] ?? 0);
        $siteId = (int) ($client['reseller_id'] ?? 0) > 0 ? (int) $client['reseller_id'] : null;

        if ($clientId <= 0 || !in_array($actorType, ['admin', 'reseller'], true)) {
            return ['success' => false, 'url' => null, 'error' => 'That account could not be opened.'];
        }

        if ((string) ($client['status'] ?? 'active') === 'closed') {
            return ['success' => false, 'url' => null, 'error' => 'That account is closed, so it cannot be signed in to.'];
        }

        $base = $this->platformBaseUrl();

        if ($siteId !== null) {
            $store = $this->stores->find($siteId);

            if ($store === null) {
                return ['success' => false, 'url' => null, 'error' => 'The store this customer belongs to no longer exists.'];
            }

            // A store customer can only sign in on the store's own site, so a store
            // with no working address (no verified domain, and no store domain set
            // by the super admin) has nowhere to open the session.
            if ($this->locator->publicHostFor($store) === null) {
                return ['success' => false, 'url' => null, 'error' => 'This store has no web address yet: it has no verified domain, and no platform address domain is set under Resellers → Platform address.'];
            }

            $base = $this->locator->baseUrlFor($store);
        }

        $token = bin2hex(random_bytes(32));
        $now = new DateTimeImmutable();

        $this->db->insert(
            'INSERT INTO client_impersonation_tokens
                (token_hash, client_id, actor_type, actor_id, actor_label, site_reseller_id, return_url, ip_address, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                hash('sha256', $token),
                $clientId,
                $actorType,
                $actorId,
                mb_substr($actorLabel, 0, 191),
                $siteId,
                mb_substr($returnUrl, 0, 500),
                $ip !== null ? mb_substr($ip, 0, 45) : null,
                $now->modify('+' . self::TTL_SECONDS . ' seconds')->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ]
        );

        return ['success' => true, 'url' => $base . '/client/impersonate/' . $token, 'error' => null];
    }

    /**
     * Redeem a ticket on the site serving this request, and sign the browser in as
     * the customer.
     *
     * @return array{success: bool, error: ?string}
     */
    public function redeem(string $token, ?string $ip = null): array
    {
        $fail = ['success' => false, 'error' => 'This sign-in link has expired or has already been used. Please start again.'];

        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return $fail;
        }

        $hash = hash('sha256', $token);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        // Consuming it IS the check: only one request can turn used_at from NULL.
        $consumed = $this->db->update(
            'UPDATE client_impersonation_tokens SET used_at = ? WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?',
            [$now, $hash, $now]
        );

        if ($consumed !== 1) {
            return $fail;
        }

        $ticket = $this->db->selectOne('SELECT * FROM client_impersonation_tokens WHERE token_hash = ? LIMIT 1', [$hash]);
        $client = $ticket === null ? null : $this->clients->find((int) $ticket['client_id']);

        if ($ticket === null || $client === null) {
            return $fail;
        }

        $ticketSite = $ticket['site_reseller_id'] !== null ? (int) $ticket['site_reseller_id'] : null;
        $clientSite = (int) ($client['reseller_id'] ?? 0) > 0 ? (int) $client['reseller_id'] : null;

        // Only on the site it names, and only while the customer still belongs there.
        if ($ticketSite !== $this->current->id() || $clientSite !== $ticketSite) {
            return $fail;
        }

        if ((string) ($client['status'] ?? 'active') === 'closed') {
            return $fail;
        }

        // Somebody already signed in on this site (a store owner looking at their own
        // store) gets their session back on return. If this is a second impersonation
        // on top of a first, the ORIGINAL person is the one to return to.
        $existing = $this->session->get(self::SESSION_KEY);
        $previous = is_array($existing) && isset($existing['previous_client_id'])
            ? $existing['previous_client_id']
            : $this->session->get('client_id');

        $this->session->regenerate();
        $this->session->set('client_id', (int) $client['id']);
        $this->session->set(self::SESSION_KEY, [
            'actor_type' => (string) $ticket['actor_type'],
            'actor_id' => (int) $ticket['actor_id'],
            'actor_label' => (string) $ticket['actor_label'],
            'return_url' => (string) $ticket['return_url'],
            'client_id' => (int) $client['id'],
            'client_name' => trim((string) $client['first_name'] . ' ' . (string) $client['last_name']),
            'previous_client_id' => $previous !== null ? (int) $previous : null,
            'started_at' => time(),
        ]);

        $isAdmin = (string) $ticket['actor_type'] === 'admin';
        $this->activity->log(
            $isAdmin ? 'admin' : 'client',
            (int) $ticket['actor_id'],
            'client.impersonation_started',
            'client',
            (int) $client['id'],
            ($isAdmin ? 'Admin' : 'Reseller (' . (string) $ticket['actor_label'] . ')')
                . ' signed in to client account #' . (int) $client['id'] . ' on ' . ($ticketSite === null ? 'the main site' : 'store #' . $ticketSite),
            $ip
        );

        return ['success' => true, 'error' => null];
    }

    /**
     * The impersonation in progress in this session, or null. Only counts while the
     * session is still signed in as the customer it started with — signing out, or
     * any other change of client, ends it.
     *
     * @return array<string, mixed>|null
     */
    public function active(): ?array
    {
        return self::activeIn($this->session->get(self::SESSION_KEY), $this->session->get('client_id'));
    }

    /**
     * Same test for code that only has the raw session (the layout).
     *
     * @return array<string, mixed>|null
     */
    public static function activeIn(mixed $data, mixed $clientId): ?array
    {
        if (!is_array($data) || $clientId === null || (int) ($data['client_id'] ?? 0) !== (int) $clientId) {
            return null;
        }

        return $data;
    }

    /** End it: restore whoever was signed in before, and say where to go back to. */
    public function end(): string
    {
        $data = $this->active();

        if ($data === null) {
            $this->session->remove(self::SESSION_KEY);

            return '/client/dashboard';
        }

        $this->session->remove(self::SESSION_KEY);

        if (!empty($data['previous_client_id'])) {
            $this->session->set('client_id', (int) $data['previous_client_id']);
        } else {
            $this->session->remove('client_id');
        }

        $this->session->regenerate();

        $return = (string) ($data['return_url'] ?? '');

        return $return !== '' ? $return : '/client/login';
    }

    /**
     * Whether a request is off-limits to a RESELLER signed in as its customer: the
     * things that would let the reseller take the account from the customer, spend the
     * customer's saved cards, or act in the customer's own reseller area.
     */
    public static function restrictedForReseller(string $method, string $path): bool
    {
        $path = '/' . trim($path, '/');

        // The customer's own reseller area (if they resell too): payouts, keys, store.
        if ($path === '/client/reseller' || str_starts_with($path, '/client/reseller/')) {
            return true;
        }

        if (strtoupper($method) !== 'POST') {
            return false;
        }

        foreach ([
            '/client/account',                 // profile, including the login email
            '/client/account/password',
            '/client/account/security-pin',
            '/client/account/security',        // two-factor
            '/client/account/security-question',
            '/client/account/privacy',         // export / erasure
            '/client/payment-methods',
            '/client/set-pin',
            '/client/affiliate',
        ] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function platformBaseUrl(): string
    {
        $url = rtrim((string) $this->config->env('APP_URL', ''), '/');

        return $url !== '' ? $url : $this->locator->platformScheme() . '://' . $this->locator->platformHost();
    }
}
