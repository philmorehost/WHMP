<?php

declare(strict_types=1);

// Lets an admin merge a duplicate/follow-up ticket into another one.
// merged_into_id marks a ticket as absorbed into another rather than
// deleting it outright — a client emailing about the old ticket number, or
// an admin opening a stale link/bookmark, still lands somewhere coherent
// (a redirect to the surviving ticket) instead of a 404 or a silently
// vanished conversation. ON DELETE SET NULL rather than CASCADE: deleting
// the surviving ticket later shouldn't cascade-delete every ticket that was
// ever merged into it.

return [
    'up' => [
        'ALTER TABLE tickets ADD COLUMN merged_into_id INT UNSIGNED NULL AFTER assigned_admin_id',
        'ALTER TABLE tickets ADD CONSTRAINT fk_tickets_merged_into FOREIGN KEY (merged_into_id) REFERENCES tickets(id) ON DELETE SET NULL',
        'ALTER TABLE tickets ADD INDEX idx_merged_into (merged_into_id)',
    ],
];
