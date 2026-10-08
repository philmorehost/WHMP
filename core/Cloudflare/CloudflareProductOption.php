<?php

declare(strict_types=1);

namespace CodeVault\Cloudflare;

use CodeVault\Database;

/**
 * The free configurable option clients use to opt in at order time:
 *
 *   group  "Cloudflare CDN & Security (Free)"
 *     - "No thanks"                       $0
 *     - "Yes — enable free Cloudflare"    $0
 *
 * priced $0 on every billing cycle, so it never changes an invoice (and resellers
 * have nothing to mark up). The admin attaches it to products from the add-on
 * settings; it is an ordinary option group, so it also shows in Products →
 * Configurable Options.
 */
final class CloudflareProductOption
{
    public const GROUP_NAME = 'Cloudflare CDN & Security (Free)';
    public const NO = 'No thanks';
    public const YES = 'Yes — enable free Cloudflare';
    public const CYCLES = ['one_time', 'monthly', 'quarterly', 'semi_annually', 'annually', 'biennially', 'triennially'];

    public function __construct(
        private readonly Database $db,
        private readonly CloudflareSettings $settings
    ) {
    }

    /**
     * Creates the group (or re-uses the configured one if it still exists).
     *
     * @return array{group: int, yes: int, created: bool}
     */
    public function ensure(): array
    {
        $group = $this->settings->optionGroupId();
        $yes = $this->settings->optionYesId();

        if ($group > 0 && $yes > 0
            && $this->db->selectOne('SELECT id FROM configurable_options WHERE id = ? AND option_group_id = ?', [$yes, $group]) !== null) {
            return ['group' => $group, 'yes' => $yes, 'created' => false];
        }

        $now = date('Y-m-d H:i:s');
        $group = (int) $this->db->insert('INSERT INTO configurable_option_groups (name, created_at, updated_at) VALUES (?, ?, ?)', [self::GROUP_NAME, $now, $now]);
        $no = (int) $this->db->insert('INSERT INTO configurable_options (option_group_id, name, sort_order, created_at, updated_at) VALUES (?, ?, 0, ?, ?)', [$group, self::NO, $now, $now]);
        $yes = (int) $this->db->insert('INSERT INTO configurable_options (option_group_id, name, sort_order, created_at, updated_at) VALUES (?, ?, 1, ?, ?)', [$group, self::YES, $now, $now]);

        foreach ([$no, $yes] as $optionId) {
            foreach (self::CYCLES as $cycle) {
                $this->db->insert('INSERT INTO configurable_option_pricing (option_id, billing_cycle, price) VALUES (?, ?, 0)', [$optionId, $cycle]);
            }
        }

        $this->settings->save(['option_group_id' => $group, 'option_yes_id' => $yes]);

        return ['group' => $group, 'yes' => $yes, 'created' => true];
    }

    /** @return array<int, int> product ids the option is attached to */
    public function attachedProductIds(): array
    {
        $group = $this->settings->optionGroupId();

        if ($group <= 0) {
            return [];
        }

        return array_map(static fn (array $r): int => (int) $r['product_id'], $this->db->select(
            'SELECT product_id FROM product_configurable_option_groups WHERE option_group_id = ?',
            [$group]
        ));
    }

    /**
     * Attaches the option to exactly these products.
     *
     * @param array<int, int> $productIds
     */
    public function syncProducts(array $productIds): void
    {
        $group = $this->ensure()['group'];
        $wanted = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
        $current = $this->attachedProductIds();

        foreach (array_diff($current, $wanted) as $productId) {
            $this->db->delete('DELETE FROM product_configurable_option_groups WHERE product_id = ? AND option_group_id = ?', [$productId, $group]);
        }

        foreach (array_diff($wanted, $current) as $productId) {
            $this->db->insert('INSERT INTO product_configurable_option_groups (product_id, option_group_id) VALUES (?, ?)', [$productId, $group]);
        }
    }

    /** @return array<int, array<string, mixed>> products that can carry it (shared and reseller hosting first) */
    public function products(): array
    {
        return $this->db->select("SELECT id, name, type, status FROM products ORDER BY (type IN ('shared', 'reseller')) DESC, name ASC");
    }
}
