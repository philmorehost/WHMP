<?php

declare(strict_types=1);

namespace CodeVault\Gdpr;

use CodeVault\Clients\ClientContactRepository;
use CodeVault\Clients\ClientRepository;

/**
 * GDPR Article 17 (right to erasure) — orchestrates ClientRepository's
 * anonymize() with the one other table that holds pure PII with no
 * financial-retention need (sub-account contacts). Invoices, services,
 * domains, and tickets are deliberately left untouched: they're financial/
 * operational records with their own legal-retention requirements, and
 * they no longer identify a real person once the client row is anonymized.
 */
final class DataErasureService
{
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly ClientContactRepository $contacts
    ) {
    }

    public function erase(int $clientId): bool
    {
        if ($this->clients->find($clientId) === null) {
            return false;
        }

        $this->contacts->deleteForClient($clientId);
        $this->clients->anonymize($clientId);

        return true;
    }
}
