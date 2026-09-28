<?php

declare(strict_types=1);

// The reseller *store* — the entity behind a white-label storefront. Until
// now a "reseller" was only api_credentials.client_id plus a declared domain,
// which is enough to hand out a key but not enough to serve a branded shop on
// the reseller's own domain.
//
// Two separate domains exist for a reseller and they are NOT the same thing:
//   - `api_credentials.reseller_domain` (migration 0184) is the domain a client
//     DECLARED to get their API key switched on. It is a gate, not a website,
//     and nothing is ever served there.
//   - `resellers.custom_domain` (here) is the storefront's web address: we
//     serve the shop on it, so it must be DNS-verified by us before it counts.
// Keeping them apart is deliberate — the key gate has its own tested behaviour
// and must not change underneath the reseller API. Collapsing them is a job for
// once storefront verification exists (see docs/RESELLER_STOREFRONT_PLAN.md §7).
//
// `custom_domain` is UNIQUE but NULL-able: MySQL allows many NULLs in a unique
// index, so any number of stores may be waiting to have a domain set, while no
// two stores can ever claim the same one.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS resellers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            slug VARCHAR(63) NOT NULL,
            status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
            brand_name VARCHAR(191) NULL,
            logo_url VARCHAR(255) NULL,
            favicon_url VARCHAR(255) NULL,
            primary_color VARCHAR(7) NULL,
            custom_domain VARCHAR(255) NULL,
            domain_verification_token VARCHAR(64) NULL,
            domain_verified_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_reseller_slug (slug),
            UNIQUE KEY uq_reseller_domain (custom_domain),
            INDEX idx_reseller_client (client_id),
            CONSTRAINT fk_resellers_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
