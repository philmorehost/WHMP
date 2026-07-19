<?php

declare(strict_types=1);

// ConnectReseller (and potentially other registrars) address a domain's
// registrant contact by a separate, registrar-issued contact ID — distinct
// from `registrar_domain_id` (the domain-level ID used for nameservers/
// lock/EPP). This is nullable and populated lazily the first time contact
// info is saved for a domain, same pattern as `registrar_domain_id` itself.

return [
    'up' => [
        'ALTER TABLE domains ADD COLUMN registrar_contact_id VARCHAR(100) NULL AFTER registrar_domain_id',
    ],
];
