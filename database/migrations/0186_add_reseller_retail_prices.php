<?php

declare(strict_types=1);

// The reseller's retail prices — what their customers pay.
//
// Three numbers now exist per item and they are all different things:
//   - list   : our catalogue price     (products/product_pricing, domain_pricing)
//   - cost   : what the reseller owes us (list less the admin's reseller discount)
//   - retail : what the customer pays  (THIS migration)
//
// `resellers.markup_percent` is the store-wide default: retail = list + markup.
// A default rather than a requirement, because most resellers want one simple
// margin across the whole catalogue and should not have to price 40 cycles by
// hand. `reseller_prices` / `reseller_domain_prices` are the exceptions — a
// specific cycle or TLD priced at an absolute figure, which is what a reseller
// needs for a loss-leader or a rounded £9.99.
//
// Both tables key on (reseller_id, item) with a UNIQUE index, so re-saving a
// price updates it rather than stacking duplicates.

return [
    'up' => [
        // 0.00 means "retail = list" — i.e. no markup — which is the only
        // safe default: it can never sell below cost, whatever the admin later
        // sets the reseller discount to.
        function (\CodeVault\Database $db): void {
            $columns = $db->select(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resellers' AND COLUMN_NAME = 'markup_percent'"
            );

            if ($columns === []) {
                $db->statement('ALTER TABLE resellers ADD COLUMN markup_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER primary_color');
            }
        },

        <<<'SQL'
        CREATE TABLE IF NOT EXISTS reseller_prices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reseller_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            billing_cycle ENUM('one_time', 'monthly', 'quarterly', 'semi_annually', 'annually', 'biennially', 'triennially') NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_reseller_product_cycle (reseller_id, product_id, billing_cycle),
            CONSTRAINT fk_reseller_prices_store FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE CASCADE,
            CONSTRAINT fk_reseller_prices_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,

        <<<'SQL'
        CREATE TABLE IF NOT EXISTS reseller_domain_prices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reseller_id INT UNSIGNED NOT NULL,
            tld VARCHAR(30) NOT NULL,
            register_price DECIMAL(10,2) NULL,
            transfer_price DECIMAL(10,2) NULL,
            renew_price DECIMAL(10,2) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_reseller_tld (reseller_id, tld),
            CONSTRAINT fk_reseller_domain_prices_store FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
