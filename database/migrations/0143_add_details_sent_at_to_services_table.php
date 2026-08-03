<?php

declare(strict_types=1);

use CodeVault\Database;

// Records when a service's access details were last emailed to the client.
//
// Without it, an admin who has just set a manually-provisioned service active
// has no way to tell whether the client was already sent their hostname, IP and
// login — so the choice is to risk sending a duplicate or to leave the client
// with nothing. email_log knows a `service_details` message went to the client,
// but carries no service id, so it cannot answer the question for a client who
// owns more than one service.
//
// A single timestamp on the service answers it exactly, and lets the button
// read "Resend" rather than "Send" once details have gone out.

return [
    'up' => [
        static function (Database $db): void {
            $exists = $db->selectOne(
                'SELECT 1 AS y FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['services', 'details_sent_at']
            );

            if ($exists !== null) {
                return;
            }

            $db->statement('ALTER TABLE services ADD COLUMN details_sent_at DATETIME NULL AFTER password');
        },
    ],
];
