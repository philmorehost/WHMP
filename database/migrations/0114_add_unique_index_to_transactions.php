<?php

declare(strict_types=1);

return [
    'up' => [
        <<<'SQL'
        ALTER TABLE transactions ADD UNIQUE KEY uniq_gateway_txn (gateway_slug, gateway_transaction_id)
        SQL,
    ],
];
