<?php

declare(strict_types=1);

namespace CodeVault\Gdpr;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Billing\InvoiceRepository;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Clients\ClientContactRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Domains\DomainRepository;
use CodeVault\Support\TicketRepository;

/**
 * Builds the "everything we hold on you" export a GDPR Article 15 (right of
 * access) request needs — one JSON-serializable array pulled from every
 * table that stores this client's data, assembled at process time (not
 * generated lazily at download time) so the export is a fixed snapshot of
 * what existed when the admin approved the request.
 */
final class DataExportService
{
    /** Activity log rows are capped the same way ActivityLogger::forSubject() itself caps them — the 500 most recent entries, not a full unbounded history. */
    private const ACTIVITY_LOG_LIMIT = 500;

    public function __construct(
        private readonly ClientRepository $clients,
        private readonly ClientContactRepository $contacts,
        private readonly ServiceRepository $services,
        private readonly DomainRepository $domains,
        private readonly InvoiceRepository $invoices,
        private readonly TicketRepository $tickets,
        private readonly ActivityLogger $activity
    ) {
    }

    /** @return array<string, mixed>|null null if the client no longer exists */
    public function export(int $clientId): ?array
    {
        $client = $this->clients->find($clientId);

        if ($client === null) {
            return null;
        }

        unset($client['password_hash'], $client['two_factor_secret'], $client['two_factor_recovery_codes']);

        $invoices = array_map(
            fn (array $invoice) => $invoice + ['items' => $this->invoices->items((int) $invoice['id'])],
            $this->invoices->forClient($clientId)
        );

        return [
            'exported_at' => date('c'),
            'profile' => $client,
            'contacts' => $this->contacts->forClient($clientId),
            'services' => $this->services->forClient($clientId),
            'domains' => $this->domains->forClient($clientId),
            'invoices' => $invoices,
            'tickets' => $this->tickets->forClient($clientId),
            'activity_log' => [
                'note' => 'Most recent ' . self::ACTIVITY_LOG_LIMIT . ' entries only.',
                'entries' => $this->activity->forSubject('client', $clientId, self::ACTIVITY_LOG_LIMIT),
            ],
        ];
    }
}
