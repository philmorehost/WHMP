<?php

declare(strict_types=1);

// Same idiom as client_credit_ledger (blueprint §4.4): balance is always
// derived from SUM(pending commissions), never a stored column that can
// drift from the underlying ledger.

return [
    'up' => [
        'ALTER TABLE affiliates DROP COLUMN balance',
    ],
];
