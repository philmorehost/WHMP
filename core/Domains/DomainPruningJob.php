<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Cart\CartService;
use CodeVault\Cron\CronJob;
use CodeVault\Cron\ReportsCronStats;
use DateTimeImmutable;

/**
 * Permanently deletes a domain once it has gone unrenewed through both its
 * grace and redemption periods, plus the admin's extra buffer — the same
 * end-of-life a real registrar enforces (they delete an unredeemed domain
 * outright, at which point it becomes registrable by anyone again). Off by
 * default: an admin has to explicitly opt in, same safety shape as
 * ServiceTerminationJob/ServicePruningJob.
 *
 * Grace and redemption are per-TLD (domain_pricing.grace_period_days /
 * redemption_period_days) — DomainSettings::deletionGraceDays() is only the
 * extra wait ON TOP of both, so a domain can never be deleted while it is
 * still inside its own TLD's redemption window, whatever that buffer is set to.
 */
final class DomainPruningJob implements CronJob, ReportsCronStats
{
    private const DEFAULT_GRACE_DAYS = 30;
    private const DEFAULT_REDEMPTION_DAYS = 30;

    /** @var array<string, int> counters for the daily activity report */
    private array $stats = [];

    public function __construct(
        private readonly DomainRepository $domains,
        private readonly DomainPricingRepository $domainPricing,
        private readonly DomainSettings $settings
    ) {
    }

    public function name(): string
    {
        return 'domain-pruning';
    }

    public function frequencyMinutes(): int
    {
        return 1440;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return $this->stats;
    }

    public function handle(): void
    {
        $this->stats = ['domains_pruned' => 0];

        if (!$this->settings->autoDeleteExpiredEnabled()) {
            return;
        }

        $today = new DateTimeImmutable('today');
        $bufferDays = $this->settings->deletionGraceDays();

        // Nothing can qualify with fewer than $bufferDays elapsed even at
        // zero grace/redemption — a safe, generous pre-filter before the
        // exact per-TLD check below.
        $cutoffDate = $today->modify("-{$bufferDays} days")->format('Y-m-d');

        $pricingByTld = [];
        foreach ($this->domainPricing->all() as $row) {
            $pricingByTld[(string) $row['tld']] = $row;
        }

        foreach ($this->domains->expiredSince($cutoffDate) as $domain) {
            $tld = CartService::tldFromDomainName((string) $domain['domain_name']);
            $pricing = $pricingByTld[$tld] ?? null;
            $totalDays = (int) ($pricing['grace_period_days'] ?? self::DEFAULT_GRACE_DAYS)
                + (int) ($pricing['redemption_period_days'] ?? self::DEFAULT_REDEMPTION_DAYS)
                + $bufferDays;

            if (!$this->isPastTotal($domain['expiry_date'] ?? null, $totalDays, $today)) {
                continue;
            }

            $this->domains->delete((int) $domain['id']);
            $this->stats['domains_pruned']++;
        }
    }

    /**
     * Whole days elapsed since expiry, compared against this domain's total
     * grace + redemption + buffer. Date-only arithmetic (both sides
     * normalised to midnight), matching ServiceTerminationJob::isPastGrace().
     */
    private function isPastTotal(?string $expiryDate, int $totalDays, DateTimeImmutable $today): bool
    {
        if ($expiryDate === null || trim($expiryDate) === '') {
            return false;
        }

        $expiry = DateTimeImmutable::createFromFormat('Y-m-d', substr($expiryDate, 0, 10));

        if ($expiry === false) {
            return false;
        }

        $elapsedDays = (int) $expiry->setTime(0, 0)->diff($today)->format('%r%a');

        return $elapsedDays > $totalDays;
    }
}
