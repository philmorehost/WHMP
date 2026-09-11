<?php

declare(strict_types=1);

// Which staff member new support tickets are auto-assigned to. '0' means
// "first available admin" (a super-admin is preferred, then any admin) —
// the same value the Mail Piping settings dropdown offers. Seeded so the
// setting is visible/known even before an admin opens that page.
//
// Read by TicketService::open() via AdminRepository::resolveDefaultAssigneeId()
// so the client portal, the admin "open ticket for client" action and the
// mail-piping cron script all land tickets on someone's desk.

return [
    'up' => [
        "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('support.auto_assign_admin_id', '0')",
    ],
];
