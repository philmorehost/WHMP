<?php

declare(strict_types=1);

// Adds a local JSON copy of a domain's registrant/WHOIS contact. Registrars
// (Upperlink in particular) can't be reached for domains that aren't in the
// reseller account, or the account is IP-whitelist-gated — so the admin
// contact page previously showed an empty form and failed to save. The local
// copy is seeded from the owning client's own details (and refreshed on every
// successful registrar round-trip), so the admin always sees a usable contact
// and edits are never lost even when the registrar rejects the push.

return [
    'up' => [
        'ALTER TABLE domains ADD COLUMN contact_data LONGTEXT NULL AFTER registrar_contact_id',
    ],
];
