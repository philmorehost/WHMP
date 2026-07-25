<?php

declare(strict_types=1);

/**
 * Makes the wallet deposit bounds configurable instead of hardcoded.
 *
 * The values match the limits that were previously compiled into
 * ClientInvoiceController, so an existing install behaves identically until an
 * admin changes them. Like every other stored amount they are in the base
 * currency and are converted to the client's own currency on the deposit form;
 * a maximum of 0 means "no upper limit".
 *
 * INSERT IGNORE so re-running never clobbers a value an admin has already set.
 */
return [
    'up' => [
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('billing.min_deposit', '10.00')",
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('billing.max_deposit', '10000.00')",
    ],
];
