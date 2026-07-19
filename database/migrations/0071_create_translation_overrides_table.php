<?php

declare(strict_types=1);

// Lets admins override individual translation strings per language from
// the admin UI without editing the file catalogs — layered on top of
// resources/lang/{code}.php at lookup time (file value wins only when no
// override row exists).

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS translation_overrides (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            language_id INT UNSIGNED NOT NULL,
            `key` VARCHAR(191) NOT NULL,
            `value` TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_language_key (language_id, `key`),
            CONSTRAINT fk_translation_overrides_language FOREIGN KEY (language_id) REFERENCES languages(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
