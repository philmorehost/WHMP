<?php

declare(strict_types=1);

// Re-runs migration 0198's domain_status backfill, for sites that ran 0198 before the
// backfill was added to it.
//
// The backfill went into 0198 after 0198 had already shipped. A site that recorded the
// original version never ran it, because the Migrator never re-runs a recorded file.
// On such a site, a store whose custom domain was already verified (and being served)
// still has domain_status = 'none'. That is not harmless: ResellerDomainSync only
// provisions APPROVED domains and takes anything else off the hosting panel, and the
// admin review queue cannot see or approve a 'none' claim.
//
// Safe to run everywhere, and a no-op where 0198's backfill already ran: since 0198,
// the code only ever writes 'none' together with clearing the domain
// (ResellerStoreRepository::setCustomDomain), so "a domain is set but its status is
// 'none'" can only be a row from before 0198. Both statements are guarded on exactly
// that.

return [
    'up' => [
        <<<'SQL'
        UPDATE resellers SET domain_status = 'approved',
               domain_reviewed_at = COALESCE(domain_reviewed_at, domain_verified_at)
         WHERE domain_verified_at IS NOT NULL
           AND custom_domain IS NOT NULL AND custom_domain <> ''
           AND domain_status = 'none'
        SQL,
        <<<'SQL'
        UPDATE resellers SET domain_status = 'pending',
               domain_requested_at = COALESCE(domain_requested_at, updated_at)
         WHERE domain_verified_at IS NULL
           AND custom_domain IS NOT NULL AND custom_domain <> ''
           AND domain_status = 'none'
        SQL,
    ],
];
