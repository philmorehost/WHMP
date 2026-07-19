<?php

declare(strict_types=1);

return [
    'up' => [
        "INSERT INTO departments (name, email, created_at, updated_at) VALUES ('General Support', NULL, NOW(), NOW())",
    ],
];
