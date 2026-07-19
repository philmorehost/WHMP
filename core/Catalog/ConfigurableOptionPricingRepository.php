<?php

declare(strict_types=1);

namespace CodeVault\Catalog;

use CodeVault\Database;

final class ConfigurableOptionPricingRepository
{
    public function __construct(
        private readonly Database $db
    ) {
    }

    /** @return array<string, array<string, mixed>> billing_cycle => pricing row */
    public function forOption(int $optionId): array
    {
        $rows = $this->db->select('SELECT * FROM configurable_option_pricing WHERE option_id = ?', [$optionId]);
        $byCycle = [];

        foreach ($rows as $row) {
            $byCycle[$row['billing_cycle']] = $row;
        }

        return $byCycle;
    }

    public function priceFor(int $optionId, string $cycle): float
    {
        $row = $this->db->selectOne('SELECT price FROM configurable_option_pricing WHERE option_id = ? AND billing_cycle = ?', [$optionId, $cycle]);

        return $row !== null ? (float) $row['price'] : 0.0;
    }

    public function setPricing(int $optionId, string $cycle, float $price): void
    {
        $existing = $this->db->selectOne('SELECT id FROM configurable_option_pricing WHERE option_id = ? AND billing_cycle = ?', [$optionId, $cycle]);

        if ($existing === null) {
            $this->db->insert(
                'INSERT INTO configurable_option_pricing (option_id, billing_cycle, price) VALUES (?, ?, ?)',
                [$optionId, $cycle, $price]
            );

            return;
        }

        $this->db->update(
            'UPDATE configurable_option_pricing SET price = ? WHERE option_id = ? AND billing_cycle = ?',
            [$price, $optionId, $cycle]
        );
    }
}
