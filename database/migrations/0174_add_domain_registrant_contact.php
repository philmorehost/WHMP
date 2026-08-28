<?php

declare(strict_types=1);

// WHMCS-style per-domain registrant contact. A domain can be registered on
// behalf of someone else (a company, a third party), so:
//   1. client_contacts grows the full WHOIS fields (company, address, city,
//      state, postcode, country, phone) so a saved contact can double as a
//      complete registrant, not just a name+email sub-account.
//   2. domains.contact_id links a domain to one of the owning client's saved
//      contacts — the registrant "on behalf of". NULL means a custom contact
//      (stored in domains.contact_data) is in use instead.

return [
    'up' => [
        'ALTER TABLE client_contacts ADD COLUMN company_name VARCHAR(191) NULL AFTER email',
        'ALTER TABLE client_contacts ADD COLUMN address1 VARCHAR(191) NULL AFTER company_name',
        'ALTER TABLE client_contacts ADD COLUMN city VARCHAR(100) NULL AFTER address1',
        'ALTER TABLE client_contacts ADD COLUMN state VARCHAR(100) NULL AFTER city',
        'ALTER TABLE client_contacts ADD COLUMN postcode VARCHAR(20) NULL AFTER state',
        "ALTER TABLE client_contacts ADD COLUMN country CHAR(2) NULL AFTER postcode",
        'ALTER TABLE client_contacts ADD COLUMN phone VARCHAR(40) NULL AFTER country',
        'ALTER TABLE domains ADD COLUMN contact_id INT UNSIGNED NULL AFTER registrar_contact_id',
        'ALTER TABLE domains ADD INDEX idx_domains_contact_id (contact_id)',
        'ALTER TABLE domains ADD CONSTRAINT fk_domains_contact FOREIGN KEY (contact_id) REFERENCES client_contacts(id) ON DELETE SET NULL',
    ],
];
