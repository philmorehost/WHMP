<?php

declare(strict_types=1);

namespace CodeVault\Reports;

use CodeVault\Billing\CurrencyService;
use CodeVault\Billing\ServiceRepository;
use CodeVault\Clients\ClientRepository;
use CodeVault\Domains\DomainRepository;

/**
 * Gathers the accounts that renew within the next 7 days — active services
 * due for billing and auto-renew domains due for renewal — grouped by
 * client, with the exact service/domain names, due dates and per-currency
 * amounts the admin can send expiry-reminder emails about.
 *
 * Shared by the admin ExpiringReminderController (page list + AI generation)
 * and the background ExpiringReminderJob that does the sending, so both see
 * exactly the same population.
 */
final class ExpiringReminderService
{
    public const DAYS_AHEAD = 7;

    public function __construct(
        private readonly ServiceRepository $services,
        private readonly DomainRepository $domains,
        private readonly ClientRepository $clients,
        private readonly CurrencyService $currency
    ) {
    }

    /**
     * @return array<int, array{client_id: int, email: string, first_name: string, last_name: string, items: array<int, array{kind: string, name: string, due_date: string, amount: string}>}>
     */
    public function accountsExpiringSoon(): array
    {
        $grouped = [];

        foreach ($this->services->dueForBilling(self::DAYS_AHEAD) as $service) {
            $clientId = (int) $service['client_id'];
            $client = $this->clientFor($clientId);

            if ($client === null) {
                continue;
            }

            $grouped[$clientId]['client_id'] = $clientId;
            $grouped[$clientId]['email'] = (string) ($client['email'] ?? '');
            $grouped[$clientId]['first_name'] = (string) ($client['first_name'] ?? '');
            $grouped[$clientId]['last_name'] = (string) ($client['last_name'] ?? '');
            $grouped[$clientId]['items'][] = [
                'kind' => 'service',
                'name' => (string) ($service['product_name'] ?? ''),
                'domain' => (string) ($service['domain'] ?: $service['hostname'] ?: ''),
                'due_date' => (string) ($service['next_due_date'] ?? ''),
                'amount' => $this->money((float) ($service['amount'] ?? 0), $client),
            ];
        }

        foreach ($this->domains->dueForRenewal(self::DAYS_AHEAD) as $domain) {
            $clientId = (int) $domain['client_id'];
            $client = $this->clientFor($clientId);

            if ($client === null) {
                continue;
            }

            $grouped[$clientId]['client_id'] = $clientId;
            $grouped[$clientId]['email'] = (string) ($client['email'] ?? '');
            $grouped[$clientId]['first_name'] = (string) ($client['first_name'] ?? '');
            $grouped[$clientId]['last_name'] = (string) ($client['last_name'] ?? '');
            $grouped[$clientId]['items'][] = [
                'kind' => 'domain',
                'name' => (string) ($domain['domain_name'] ?? ''),
                'domain' => (string) ($domain['domain_name'] ?? ''),
                'due_date' => (string) ($domain['next_due_date'] ?? ''),
                'amount' => $this->money((float) ($domain['amount'] ?? 0), $client),
            ];
        }

        return array_values($grouped);
    }

    /** @var array<int, array<string, mixed>|null> client id => resolved client, per request */
    private array $clientCache = [];

    /** @return array<string, mixed>|null */
    private function clientFor(int $clientId): ?array
    {
        if (!array_key_exists($clientId, $this->clientCache)) {
            $this->clientCache[$clientId] = $this->clients->find($clientId);
        }

        return $this->clientCache[$clientId];
    }

    /** @param array<string, mixed> $client */
    private function money(float $amount, array $client): string
    {
        $currency = $this->currency->resolveForClient($client);

        return ($currency['symbol'] ?? '$') . number_format($amount, 2);
    }
}
