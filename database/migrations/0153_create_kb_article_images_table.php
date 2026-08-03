<?php

declare(strict_types=1);

// Images attached to a KB article — either an admin upload (stored_name
// points at a file under storage/kb_article_images/) or an AI-generated
// diagram (svg_content holds the sanitized <svg> markup directly, no file on
// disk). Both are served through the same route so the article body only
// ever needs a plain <img src> regardless of where the image came from —
// see KbImageController::serve() and KbArticleRenderer.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS kb_article_images (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            article_id INT UNSIGNED NOT NULL,
            source ENUM('upload', 'ai_generated') NOT NULL DEFAULT 'upload',
            original_name VARCHAR(255) NULL,
            stored_name VARCHAR(255) NULL,
            svg_content MEDIUMTEXT NULL,
            mime_type VARCHAR(120) NULL,
            size_bytes INT UNSIGNED NULL,
            caption VARCHAR(255) NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            INDEX idx_article (article_id),
            CONSTRAINT fk_kbimg_article FOREIGN KEY (article_id) REFERENCES kb_articles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
