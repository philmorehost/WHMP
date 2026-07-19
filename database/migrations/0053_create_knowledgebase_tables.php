<?php

declare(strict_types=1);

// helpful_count/unhelpful_count back the KB rating blueprint §4.1 calls
// for ("Knowledgebase (+rating/search)"); search is a plain LIKE query
// against title/body at query time, not a separate index table.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS kb_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS kb_articles (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            category_id INT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            body LONGTEXT NOT NULL,
            views INT UNSIGNED NOT NULL DEFAULT 0,
            helpful_count INT UNSIGNED NOT NULL DEFAULT 0,
            unhelpful_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_category (category_id),
            CONSTRAINT fk_kb_articles_category FOREIGN KEY (category_id) REFERENCES kb_categories(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
