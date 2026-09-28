<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * The reseller's price OVERRIDES — the individual cycles and TLDs they have
 * priced by hand, as opposed to the store-wide markup that covers everything
 * else.
 *
 * Overrides are sparse on purpose: an empty table means "this store uses its
 * markup for everything", which is the normal case, and rows exist only where
 * the reseller has deliberately priced something differently.
 */
final class ResellerRetailPriceRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * Product-cycle overrides, keyed "{productId}:{cycle}" so a lookup during a
     * catalogue render is an array hit rather than a query per line.
     *
     * @return array<string, float>
     */
    public function productOverrides(int $resellerId): array
    {
        $rows = $this->db->select(
            'SELECT product_id, billing_cycle, price FROM reseller_prices WHERE reseller_id = ?',
            [$resellerId]
        );

        $prices = [];

        foreach ($rows as $row) {
            $prices[(int) $row['product_id'] . ':' . (string) $row['billing_cycle']] = (float) $row['price'];
        }

        return $prices;
    }

    /**
     * Domain overrides keyed by TLD, each with the three prices a domain can
     * have. A NULL member means that particular price is not overridden (so it
     * falls back to the markup) — a reseller may well care about the register
     * price and not the renewal.
     *
     * @return array<string, array{register: ?float, transfer: ?float, renew: ?float}>
     */
    public function domainOverrides(int $resellerId): array
    {
        $rows = $this->db->select(
            'SELECT tld, register_price, transfer_price, renew_price FROM reseller_domain_prices WHERE reseller_id = ?',
            [$resellerId]
        );

        $prices = [];

        foreach ($rows as $row) {
            $prices[(string) $row['tld']] = [
                'register' => $row['register_price'] === null ? null : (float) $row['register_price'],
                'transfer' => $row['transfer_price'] === null ? null : (float) $row['transfer_price'],
                'renew' => $row['renew_price'] === null ? null : (float) $row['renew_price'],
            ];
        }

        return $prices;
    }

    public function countFor(int $resellerId): int
    {
        $row = $this->db->selectOne(
            'SELECT (SELECT COUNT(*) FROM reseller_prices WHERE reseller_id = ?) + (SELECT COUNT(*) FROM reseller_domain_prices WHERE reseller_id = ?) AS c',
            [$resellerId, $resellerId]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Replaces the store's product overrides wholesale.
     *
     * This is a delete-and-reinsert, which is usually a smell — but it is the
     * honest shape here: the form submits the complete set of prices, and a
     * blank field means "stop overriding this one". Nothing references these
     * rows by id, so replacing them cannot orphan anything.
     *
     * @param array<string, float|null> $prices keyed "{productId}:{cycle}"; a null value clears the override
     */
    public function replaceProductOverrides(int $resellerId, array $prices): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $rows = [];

        foreach ($prices as $key => $price) {
            if ($price === null) {
                continue;
            }

            [$productId, $cycle] = explode(':', (string) $key, 2) + [1 => ''];

            if ($productId === '' || $cycle === '') {
                continue;
            }

            $rows[] = [(int) $productId, $cycle, round($price, 2)];
        }

        $this->db->transaction(function () use ($resellerId, $rows, $now): void {
            $this->db->delete('DELETE FROM reseller_prices WHERE reseller_id = ?', [$resellerId]);

            foreach ($rows as $row) {
                $this->db->insert(
                    'INSERT INTO reseller_prices (product_id, billing_cycle, price, reseller_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$row[0], $row[1], $row[2], $resellerId, $now, $now]
                );
            }

            // Keep updated_at on the store honest even when only prices moved.
            $this->db->update('UPDATE resellers SET updated_at = ? WHERE id = ?', [$now, $resellerId]);
        });
    }

    /**
     * @param array<string, array{register: ?float, transfer: ?float, renew: ?float}> $prices keyed by TLD
     */
    public function replaceDomainOverrides(int $resellerId, array $prices): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $rows = [];

        foreach ($prices as $tld => $member) {
            $register = $member['register'] ?? null;
            $transfer = $member['transfer'] ?? null;
            $renew = $member['renew'] ?? null;

            // All three empty means "no override for this TLD" — drop the row
            // rather than storing three NULLs.
            if ($register === null && $transfer === null && $renew === null) {
                continue;
            }

            $rows[] = [
                (string) $tld,
                $register === null ? null : round($register, 2),
                $transfer === null ? null : round($transfer, 2),
                $renew === null ? null : round($renew, 2),
            ];
        }

        $this->db->transaction(function () use ($resellerId, $rows, $now): void {
            $this->db->delete('DELETE FROM reseller_domain_prices WHERE reseller_id = ?', [$resellerId]);

            foreach ($rows as $row) {
                $this->db->insert(
                    'INSERT INTO reseller_domain_prices (reseller_id, tld, register_price, transfer_price, renew_price, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$resellerId, $row[0], $row[1], $row[2], $row[3], $now, $now]
                );
            }

            $this->db->update('UPDATE resellers SET updated_at = ? WHERE id = ?', [$now, $resellerId]);
        });
    }
}
