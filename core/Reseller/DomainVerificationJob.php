<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Activity\ActivityLogger;
use CodeVault\Cron\CronJob;

/**
 * Re-checks claimed-but-unverified store domains once a day.
 *
 * Without this, a reseller who adds their TXT record at midnight has to come
 * back and press "Verify" — or open a ticket asking us to. With it, a domain
 * goes live on its own once DNS answers, and the reseller only presses anything
 * if they are impatient (the button on the store page still works, and is the
 * same code path).
 *
 * **It verifies and never revokes.** A store that is already verified is not
 * looked at again, so a transient DNS failure at 03:00 cannot take a working
 * storefront offline. Once control of a domain has been proved, that proof does
 * not expire; the risk it guards against — someone claiming a hostname they do
 * not control — is settled at the moment of verification and cannot come back.
 */
final class DomainVerificationJob implements CronJob
{
    public function __construct(
        private readonly ResellerStoreRepository $stores,
        private readonly ResellerStoreService $service,
        private readonly ActivityLogger $activity
    ) {
    }

    public function name(): string
    {
        return 'reseller-domain-verification';
    }

    public function frequencyMinutes(): int
    {
        return 1440;
    }

    public function handle(): void
    {
        foreach ($this->stores->pendingDomainClaims() as $store) {
            $storeId = (int) $store['id'];

            // The service re-reads the store and owns the rule for what
            // "verified" means and how it is recorded, so the scheduler and the
            // button cannot drift apart.
            $result = $this->service->verifyDomain($storeId);

            if (!$result['verified']) {
                continue;
            }

            $this->activity->log(
                'system',
                null,
                'reseller.store.domain_verified',
                'reseller',
                $storeId,
                'Verified store domain ' . (string) $store['custom_domain'] . ' via ' . (string) $result['method'],
                null
            );
        }
    }
}
