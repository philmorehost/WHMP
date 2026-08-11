<?php

declare(strict_types=1);

namespace CodeVault\Catalog;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Product add-on configuration (admin-managed) + the lookups the client
 * self-service surface needs. Defines which products may be sold as
 * recurring add-ons to a service whose product is `parent_product_id`.
 *
 * The add-on's own product_pricing rows are the price source — the config
 * row's `billing_cycle` is either NULL (follow the parent service's cycle)
 * or pinned to one specific cycle.
 */
final class ProductAddonRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * Configure a product as an add-on for a parent product.
     *
     * @param string|null $billingCycle null = available on any cycle of the parent
     */
    public function attach(int $parentProductId, int $addonProductId, ?string $billingCycle = null, int $sortOrder = 0): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->statement(
            <<<'SQL'
            INSERT INTO product_addons (parent_product_id, addon_product_id, billing_cycle, sort_order, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'active', ?, ?)
            ON DUPLICATE KEY UPDATE status = 'active', updated_at = VALUES(updated_at)
            SQL,
            [$parentProductId, $addonProductId, $billingCycle, $sortOrder, $now, $now]
        );
    }

    public function detach(int $parentProductId, int $addonProductId): void
    {
        $this->db->delete(
            'DELETE FROM product_addons WHERE parent_product_id = ? AND addon_product_id = ?',
            [$parentProductId, $addonProductId]
        );
    }

    public function deleteById(int $id): void
    {
        $this->db->delete('DELETE FROM product_addons WHERE id = ?', [$id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->update(
            'UPDATE product_addons SET status = ?, updated_at = ? WHERE id = ?',
            [$status, (new DateTimeImmutable())->format('Y-m-d H:i:s'), $id]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function forParentProduct(int $parentProductId): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT pa.*, p.name AS addon_name, p.status AS addon_status, p.type AS addon_type
            FROM product_addons pa
            JOIN products p ON p.id = pa.addon_product_id
            WHERE pa.parent_product_id = ?
            ORDER BY pa.sort_order ASC, p.name ASC
            SQL,
            [$parentProductId]
        );
    }

    /**
     * Active add-ons available to a service of the given parent product,
     * for the given billing cycle, with the add-on's own price for that
     * cycle attached. A config row pinned to a specific cycle only shows on
     * that cycle; an unpinned row (billing_cycle IS NULL) shows on any cycle
     * the add-on actually has pricing for.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableFor(int $parentProductId, string $billingCycle): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT pa.id AS config_id, pa.billing_cycle AS pinned_cycle, pa.sort_order,
                   p.id AS product_id, p.name AS addon_name, p.type AS addon_type, p.status AS addon_status,
                   pp.price, pp.setup_fee
            FROM product_addons pa
            JOIN products p ON p.id = pa.addon_product_id
            JOIN product_pricing pp ON pp.product_id = p.id AND pp.billing_cycle = ?
            WHERE pa.parent_product_id = ?
              AND pa.status = 'active'
              AND p.status = 'active'
              AND (pa.billing_cycle IS NULL OR pa.billing_cycle = ?)
            ORDER BY pa.sort_order ASC, p.name ASC
            SQL,
            [$billingCycle, $parentProductId, $billingCycle]
        );
    }

    /**
     * All add-ons across all parent products — for the admin add-ons page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->select(
            <<<'SQL'
            SELECT pa.*, parent.name AS parent_product_name, addon.name AS addon_name
            FROM product_addons pa
            JOIN products parent ON parent.id = pa.parent_product_id
            JOIN products addon ON addon.id = pa.addon_product_id
            ORDER BY parent.name ASC, addon.name ASC
            SQL
        );
    }
}
