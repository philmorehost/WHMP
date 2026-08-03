<?php

declare(strict_types=1);

namespace CodeVault\Domains;

use CodeVault\Settings\SettingsRepository;

/**
 * Default nameservers (WHMCS General Settings > Domains tab equivalent) —
 * used to pre-fill a new domain registration when the client doesn't
 * specify their own, same role WHMCS's ns1-ns5 fields play (this app
 * allows up to 6, since some hosts run 4+ nameservers). Thin wrapper
 * over the existing key/value SettingsRepository, same pattern as
 * TaxSettings/ThemeSettings.
 */
final class DomainSettings
{
    private const KEY_PREFIX = 'domains.default_ns_';
    private const MAX_NAMESERVERS = 6;

    public function __construct(
        private readonly SettingsRepository $settings
    ) {
    }

    /** @return array<int, string> non-empty nameservers only, in ns1..ns5 order */
    public function defaultNameservers(): array
    {
        $nameservers = [];

        for ($i = 1; $i <= self::MAX_NAMESERVERS; $i++) {
            $value = trim((string) $this->settings->get(self::KEY_PREFIX . $i, ''));

            if ($value !== '') {
                $nameservers[] = $value;
            }
        }

        return $nameservers;
    }

    /** @param array<int, string> $nameservers up to 5 values, ns1 first */
    public function setDefaultNameservers(array $nameservers): void
    {
        $nameservers = array_values(array_filter(array_map('trim', $nameservers), static fn (string $ns) => $ns !== ''));

        for ($i = 1; $i <= self::MAX_NAMESERVERS; $i++) {
            $this->settings->set(self::KEY_PREFIX . $i, $nameservers[$i - 1] ?? '');
        }
    }

    public function autoDeleteExpiredEnabled(): bool
    {
        return $this->settings->get('domains.auto_delete_expired_enabled', '0') === '1';
    }

    /**
     * Extra days to wait *after* a domain's grace + redemption periods (per
     * TLD, from domain_pricing) have both elapsed before the domain row is
     * deleted outright. Not the total time since expiry — DomainPruningJob
     * always adds this on top of the TLD's own grace/redemption, so a low or
     * zero value here can never delete a domain still inside its redemption
     * window, whatever the admin sets it to.
     */
    public function deletionGraceDays(): int
    {
        $value = $this->settings->get('domains.deletion_grace_days', '30');

        if ($value === null || trim($value) === '' || !is_numeric($value)) {
            return 30;
        }

        return max(0, (int) $value);
    }

    public function saveDeletionPolicy(bool $enabled, int $days): void
    {
        $this->settings->set('domains.auto_delete_expired_enabled', $enabled ? '1' : '0');
        $this->settings->set('domains.deletion_grace_days', (string) max(0, $days));
    }
}
