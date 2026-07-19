<?php

declare(strict_types=1);

// R28 — SecurityQuestionModule activation state (mirrors R20/R21/R27's
// addon/widget/report_modules tables) plus per-client answer storage.
// Unlike those three, SecurityQuestionModule activation is only half the
// picture: a client also has to individually opt in and set their own
// answer before the feature does anything for them, hence the second
// table. `client_security_answers` is keyed by client_id (one configured
// question per client at a time, matching how WHMCS's own single security
// question field works) rather than a composite key — a client switching
// questions simply overwrites their prior answer.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS security_question_modules (
            slug VARCHAR(64) NOT NULL PRIMARY KEY,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            config TEXT NULL,
            activated_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS client_security_answers (
            client_id INT UNSIGNED NOT NULL PRIMARY KEY,
            module_slug VARCHAR(64) NOT NULL,
            answer_hash VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            CONSTRAINT fk_client_security_answers_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,
    ],
];
