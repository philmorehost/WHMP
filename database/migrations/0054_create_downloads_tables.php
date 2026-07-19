<?php

declare(strict_types=1);

// `file_path` is relative to storage/downloads/ (outside the public
// webroot — the client controller streams the file through a download
// handler rather than exposing a direct static URL, so download_count can
// actually be tracked).

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS download_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS downloads (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            category_id INT UNSIGNED NOT NULL,
            name VARCHAR(191) NOT NULL,
            description TEXT NULL,
            file_path VARCHAR(255) NOT NULL,
            file_size INT UNSIGNED NULL,
            download_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_category (category_id),
            CONSTRAINT fk_downloads_category FOREIGN KEY (category_id) REFERENCES download_categories(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
