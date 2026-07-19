<?php

declare(strict_types=1);

// ConnectReseller (and its domain/contact endpoints specifically) address
// everything under a customer record in *its own* system — register/renew/
// AddRegistrantContact all take a required "Id" that is this customer ID,
// not any of our local IDs. This column is populated lazily the first time
// a domain action needs one (via ConnectResellerRegistrarModule's
// AddClient + ViewClient lookup), the same lazy pattern as
// domains.registrar_domain_id / domains.registrar_contact_id.

return [
    'up' => [
        'ALTER TABLE clients ADD COLUMN registrar_client_id VARCHAR(100) NULL AFTER client_group_id',
    ],
];
