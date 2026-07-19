<?php

declare(strict_types=1);

// A client's preferred language (blueprint §5 localization). NULL = use
// the system default language.

return [
    'up' => [
        'ALTER TABLE clients ADD COLUMN language_id INT UNSIGNED NULL AFTER currency_id',
        'ALTER TABLE clients ADD CONSTRAINT fk_clients_language FOREIGN KEY (language_id) REFERENCES languages(id) ON DELETE SET NULL',
    ],
];
