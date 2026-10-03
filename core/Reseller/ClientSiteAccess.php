<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Database;

/**
 * Which SITE a client account belongs to — the platform's own, or one store — and
 * therefore where it may sign in and what it is told when it tries to register twice.
 *
 * THE RULE IS clients.reseller_id, AND NOTHING ELSE
 *
 * A store's customer bought from the reseller and is billed by the reseller. Letting
 * that account sign in on a different store would show one reseller's customer (and
 * their services, invoices and tickets) under another reseller's brand; letting it sign
 * in on the platform's own site would tell the customer who the reseller buys from.
 * Both break the isolation stores are promised, so an account works on exactly one site:
 *
 *   reseller_id = N     store N's site, and only there
 *   reseller_id = NULL  the platform's site
 *
 * TWO DELIBERATE EXCEPTIONS
 *
 * 1. A store's OWNER on their own store. The owner is a platform client (they buy from
 *    us), but they must be able to sign in on the store they run to see what their
 *    customers see.
 *
 * 2. An UNCLAIMED account: reseller_id NULL and no history at all — no order, service,
 *    domain or invoice. That is somebody who registered and never bought. Nothing about
 *    them belongs to anyone yet, so refusing them would be pure friction; they may sign
 *    in anywhere, and the first store they order from claims them
 *    (ClientRepository::setResellerIfUnclaimed, the rule that already existed). The
 *    moment they have history on the platform, they are the platform's.
 *
 * "ANOTHER PROVIDER" IS ALL ANYONE IS TOLD
 *
 * Never which store, and never that the platform exists behind a store: the message
 * a refused visitor sees is the same whichever site the account belongs to. Moving an
 * account between sites is a deliberate, audited act (ClientMigrationService), never
 * a side effect of signing in somewhere.
 */
final class ClientSiteAccess
{
    public const SAME_SITE = 'same_site';
    public const OTHER_PROVIDER = 'other_provider';

    public function __construct(
        private readonly Database $db,
        private readonly CurrentReseller $current
    ) {
    }

    /**
     * The pure decision, separated from the queries so every branch is testable
     * without a database.
     *
     * @param int|null $ownerStoreId      clients.reseller_id
     * @param int|null $siteStoreId       the store this request is for (null = platform)
     * @param int|null $siteOwnerClientId resellers.client_id of that store
     */
    public static function decide(
        int $clientId,
        ?int $ownerStoreId,
        ?int $siteStoreId,
        ?int $siteOwnerClientId,
        bool $hasHistory
    ): string {
        if ($ownerStoreId === $siteStoreId) {
            return self::SAME_SITE;
        }

        // Exception 1: the store's owner, on the store they run.
        if ($siteStoreId !== null && $siteOwnerClientId !== null && $siteOwnerClientId === $clientId) {
            return self::SAME_SITE;
        }

        // Exception 2: a platform account that has never bought anything.
        if ($ownerStoreId === null && !$hasHistory) {
            return self::SAME_SITE;
        }

        return self::OTHER_PROVIDER;
    }

    /**
     * Where this client stands on the site being served right now.
     *
     * @param array<string, mixed> $client
     */
    public function standingHere(array $client): string
    {
        $site = $this->current->get();
        $clientId = (int) ($client['id'] ?? 0);
        $owner = self::ownerOf($client);
        $siteId = $site === null ? null : (int) $site['id'];

        // The cheap answer first: the common case needs no history query at all.
        if ($owner === $siteId) {
            return self::SAME_SITE;
        }

        return self::decide(
            $clientId,
            $owner,
            $siteId,
            $site === null || empty($site['client_id']) ? null : (int) $site['client_id'],
            $owner === null ? $this->hasHistory($clientId) : true
        );
    }

    /** @param array<string, mixed> $client */
    public function canSignInHere(array $client): bool
    {
        return $this->standingHere($client) === self::SAME_SITE;
    }

    /**
     * True when the account has nothing on the platform yet, so the store it next
     * deals with may claim it.
     *
     * @param array<string, mixed> $client
     */
    public function isUnclaimed(array $client): bool
    {
        return self::ownerOf($client) === null && !$this->hasHistory((int) ($client['id'] ?? 0));
    }

    /**
     * Whether the client has bought anything: an order, a service, a domain or an
     * invoice. Tickets are deliberately NOT history — asking a question before buying
     * does not make somebody the platform's customer.
     */
    public function hasHistory(int $clientId): bool
    {
        if ($clientId <= 0) {
            return false;
        }

        foreach (['orders', 'services', 'domains', 'invoices'] as $table) {
            if ($this->db->selectOne('SELECT id FROM ' . $table . ' WHERE client_id = ? LIMIT 1', [$clientId]) !== null) {
                return true;
            }
        }

        return false;
    }

    /** The message every refusal uses — identical on every site, on purpose. */
    public static function otherProviderMessage(): string
    {
        return 'An account with this email address already exists with another provider on this platform. '
            . 'Please sign in on the website where you created it. If you would like to move that account here, '
            . 'sign in there and open a support ticket asking for an account move — or use a different email address to register here.';
    }

    /** @param array<string, mixed> $client */
    private static function ownerOf(array $client): ?int
    {
        $owner = (int) ($client['reseller_id'] ?? 0);

        return $owner > 0 ? $owner : null;
    }
}
