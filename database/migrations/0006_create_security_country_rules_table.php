<?php

declare(strict_types=1);

// MaxMind country rules (blueprint §5): every country is one of
// Whitelisted / Not Specified / Blacklisted. Rows only exist for countries
// an admin has explicitly set — "not specified" is the implicit default
// for everything else, not a row that has to be seeded.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS security_country_rules (
            country_code CHAR(2) NOT NULL PRIMARY KEY,
            policy ENUM('whitelisted', 'not_specified', 'blacklisted') NOT NULL DEFAULT 'not_specified',
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
