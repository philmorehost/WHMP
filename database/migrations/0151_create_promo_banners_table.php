<?php

declare(strict_types=1);

// Admin-managed promo popups (Marketing → Promo Banners) — a WHMCS-affiliate-style
// popup advertising a promotions.code, shown on admin-chosen public pages. The
// coupon itself still lives in and is validated by the existing `promotions`
// table/PromotionService; this table only owns the *presentation* (copy,
// curated visual template, target pages, schedule) and click/impression counts.
//
// target_pages is a JSON array of page keys ('home','store','cart','domains',
// 'client') or the single value ["all"] — kept as free-form JSON rather than a
// join table since the page-key set is small and admin-curated, not a growing
// foreign entity.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS promo_banners (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            template VARCHAR(30) NOT NULL DEFAULT 'sunset',
            eyebrow_text VARCHAR(120) NULL,
            headline VARCHAR(150) NOT NULL,
            subtext VARCHAR(300) NULL,
            coupon_code VARCHAR(50) NOT NULL,
            cta_text VARCHAR(40) NOT NULL DEFAULT 'Apply Now',
            target_pages VARCHAR(255) NOT NULL DEFAULT '["all"]',
            status ENUM('active', 'paused') NOT NULL DEFAULT 'active',
            starts_at DATE NULL,
            expires_at DATE NULL,
            impressions INT UNSIGNED NOT NULL DEFAULT 0,
            clicks INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
