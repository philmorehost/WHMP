<?php

declare(strict_types=1);

// Fixed-window rate limiting for the external REST API (see
// ApiRateLimiter). One row per (identifier, window) with an atomic
// upsert-on-hit; the primary key doubles as the cleanup index. Re-runnable:
// CREATE TABLE IF NOT EXISTS, because the migrator heals the schema on boot.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS api_rate_limits (
            identifier VARCHAR(191) NOT NULL,
            window_start INT UNSIGNED NOT NULL,
            hits INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (identifier, window_start),
            KEY idx_api_rate_limits_window (window_start)
        )
        SQL,
    ],
];
