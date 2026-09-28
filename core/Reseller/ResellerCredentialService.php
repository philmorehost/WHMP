<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Api\DatabaseApiCredentialRepository;

/**
 * A reseller's API key: how it is requested, what unlocks it, and what it may do.
 *
 * The rule this class exists to enforce: **a reseller key does nothing until the
 * client tells us the domain they will sell from.** A key is minted inactive
 * (DatabaseApiCredentialRepository::createForClient), and the only thing that
 * switches it on is activate() with a domain that parses as a hostname. That
 * makes the declaration, not the click, the gate — so an admin can always see
 * which storefront a key was issued against.
 *
 * Scopes are deliberately minimal. Every other scope in the API's catalog opens
 * install-wide data (all clients, all invoices), so a client-owned credential
 * gets 'reseller.read' and nothing else — it can price its own catalogue and
 * cannot read anyone else's account.
 */
final class ResellerCredentialService
{
    /** Read-only catalogue pricing. Nothing else is granted to a reseller key. */
    public const SCOPES = ['reseller.read'];

    public function __construct(
        private readonly DatabaseApiCredentialRepository $credentials
    ) {
    }

    /** @return array<string, mixed>|null the raw row, for the client/admin pages */
    public function credentialFor(int $clientId): ?array
    {
        return $this->credentials->forClient($clientId);
    }

    public function hasKey(int $clientId): bool
    {
        return $this->credentialFor($clientId) !== null;
    }

    /** True only for a key that has been activated with a storefront domain. */
    public function isActiveReseller(int $clientId): bool
    {
        $credential = $this->credentialFor($clientId);

        return $credential !== null && (int) $credential['active'] === 1;
    }

    /**
     * Creates the client's key, inactive. Idempotent — asking twice does not
     * mint a second key, and the second call cannot return the secret because
     * only its hash was kept (the caller should offer rotate() instead).
     *
     * @return array{created: bool, key: ?string, secret: ?string}
     */
    public function requestKey(int $clientId, string $label): array
    {
        if ($this->hasKey($clientId)) {
            return ['created' => false, 'key' => null, 'secret' => null];
        }

        $created = $this->credentials->createForClient($clientId, $label, self::SCOPES);

        return ['created' => true, 'key' => $created['key'], 'secret' => $created['secret']];
    }

    /**
     * Replaces the client's key with a fresh inactive one, for when the secret
     * was lost or leaked. The old key stops working immediately.
     *
     * @return array{key: string, secret: string}
     */
    public function rotateKey(int $clientId, string $label): array
    {
        $existing = $this->credentialFor($clientId);

        if ($existing !== null) {
            $this->credentials->delete((int) $existing['id']);
        }

        $created = $this->credentials->createForClient($clientId, $label, self::SCOPES);

        return ['key' => $created['key'], 'secret' => $created['secret']];
    }

    /**
     * Activates the client's key against the domain they will sell from.
     *
     * @return array{success: bool, error: ?string, domain: ?string}
     */
    public function activate(int $clientId, string $domain): array
    {
        $credential = $this->credentialFor($clientId);

        if ($credential === null) {
            return ['success' => false, 'error' => 'Request an API key first, then activate it with your domain.', 'domain' => null];
        }

        $normalised = self::normaliseDomain($domain);

        if ($normalised === null) {
            return ['success' => false, 'error' => 'Enter a domain name like reseller.example.com — letters, digits and hyphens only.', 'domain' => null];
        }

        if (!$this->credentials->activateForClient((int) $credential['id'], $clientId, $normalised)) {
            return ['success' => false, 'error' => 'That API key could not be activated.', 'domain' => null];
        }

        return ['success' => true, 'error' => null, 'domain' => $normalised];
    }

    /** Switches an existing key off (or back on) without forgetting its domain. */
    public function setEnabled(int $clientId, bool $enabled): bool
    {
        $credential = $this->credentialFor($clientId);

        if ($credential === null) {
            return false;
        }

        return $this->credentials->setActiveForClient((int) $credential['id'], $clientId, $enabled);
    }

    /** @return array<int, array<string, mixed>> the admin list, with client details attached */
    public function all(): array
    {
        return $this->credentials->allResellers();
    }

    /**
     * A storefront domain, or null when the value is not one.
     *
     * Deliberately strict, because this value is shown to admins as "the site
     * this key was issued against" and is the only thing standing between the
     * click and an active key. It accepts what someone would paste — a full URL,
     * a trailing slash, a port, a www. — and rejects anything that isn't a
     * hostname, including bare IP addresses (nobody resells from a bare IP, and
     * accepting one invites "activate me" with the server's own address).
     */
    public static function normaliseDomain(string $domain): ?string
    {
        $domain = trim($domain);

        if ($domain === '') {
            return null;
        }

        // Strip a scheme, then anything from the first path/query/fragment on.
        $domain = (string) preg_replace('~^[a-z][a-z0-9+.\-]*://~i', '', $domain);
        $domain = (string) preg_split('~[/?#]~', $domain)[0];
        $domain = (string) preg_replace('~:\d+$~', '', $domain);
        $domain = strtolower(rtrim($domain, '.'));

        if ($domain === '' || strlen($domain) > 253) {
            return null;
        }

        // A hostname must have a TLD, and an IP is not one.
        if (!str_contains($domain, '.') || filter_var($domain, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        foreach (explode('.', $domain) as $label) {
            // RFC 1035 shape: 1–63 chars, alphanumeric, hyphens inside only.
            if ($label === '' || strlen($label) > 63 || preg_match('~^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$~', $label) !== 1) {
                return null;
            }
        }

        $tld = substr($domain, (int) strrpos($domain, '.') + 1);

        return preg_match('~^[a-z]{2,}$~', $tld) === 1 ? $domain : null;
    }
}
