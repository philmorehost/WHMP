<?php

declare(strict_types=1);

// Records every AI provider call for the admin AI-management dashboard
// (usage monitoring). Token counts come from the provider's response when
// available (DeepSeek/OpenAI return a `usage` block); `feature` labels which
// part of the app made the call so usage can be broken down later.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS ai_usage_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            feature VARCHAR(60) NOT NULL DEFAULT 'general',
            provider VARCHAR(40) NOT NULL DEFAULT 'deepseek',
            prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            success TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            INDEX idx_created (created_at),
            INDEX idx_feature (feature)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
