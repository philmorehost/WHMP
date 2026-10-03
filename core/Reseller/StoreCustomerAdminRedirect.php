<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Database;

/**
 * STRICT RESELLER ISOLATION on the admin side.
 *
 * A reseller store's customer belongs to that store, so the admin's general client
 * pages never open one. They are viewed and managed from the reseller's page
 * (/admin/resellers/{owner user ID}/customers/{id}) or from inside the reseller's own
 * account. Every /admin/clients/{id}[/...] URL for such a customer — bookmarks, old
 * links, links on invoice and service pages, form posts — is sent there instead
 * (Kernel, before routing, so no admin client action can run against one).
 *
 * Platform customers, including the resellers themselves (whose own account is a
 * platform account), are not affected.
 */
final class StoreCustomerAdminRedirect
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Where to send a request for $path instead, or null to let it through. */
    public function targetFor(string $path): ?string
    {
        $clientId = self::clientIdIn($path);

        if ($clientId === null) {
            return null;
        }

        $owner = $this->db->selectOne(
            'SELECT r.client_id AS owner_client_id FROM clients c JOIN resellers r ON r.id = c.reseller_id WHERE c.id = ? LIMIT 1',
            [$clientId]
        );

        if ($owner === null || (int) ($owner['owner_client_id'] ?? 0) <= 0) {
            return null;
        }

        return '/admin/resellers/' . (int) $owner['owner_client_id'] . '/customers/' . $clientId;
    }

    /** The client id in an /admin/clients/{id}[/...] path, or null for any other path. */
    public static function clientIdIn(string $path): ?int
    {
        if (preg_match('#^/admin/clients/(\d+)(?:/|$)#', $path, $match) !== 1) {
            return null;
        }

        $id = (int) $match[1];

        return $id > 0 ? $id : null;
    }
}
