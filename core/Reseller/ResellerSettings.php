<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Settings\SettingsRepository;

/**
 * The two reseller discounts, as configured by an admin.
 *
 * They are percentages off list price — one for services (hosting plans and
 * their add-ons) and one for domain registrations/transfers — because those
 * are the two things a reseller sells, they are priced from two different
 * tables, and a reseller typically wants a different margin on each.
 *
 * Stored in `settings`, like every other admin tunable, so there is no second
 * source of truth and the values survive an update. The maximum is enforced
 * here rather than in the form alone: a typo of "1000" must not become a
 * negative price, and these figures are read on a money path.
 */
final class ResellerSettings
{
    public const KEY_SERVICES = 'reseller.discount_services';
    public const KEY_DOMAINS = 'reseller.discount_domains';

    /** The two price kinds a reseller discount applies to. */
    public const KINDS = ['service', 'domain'];

    public function __construct(
        private readonly SettingsRepository $settings
    ) {
    }

    public function serviceDiscount(): float
    {
        return $this->discountFor('service');
    }

    public function domainDiscount(): float
    {
        return $this->discountFor('domain');
    }

    /** The configured percentage off list price for one kind of item. */
    public function discountFor(string $kind): float
    {
        $stored = $this->settings->get(self::keyFor($kind), '0');

        return self::clampPercent((float) ($stored ?? 0));
    }

    /** @return array{service: float, domain: float} */
    public function all(): array
    {
        return [
            'service' => $this->discountFor('service'),
            'domain' => $this->discountFor('domain'),
        ];
    }

    /** Persists both percentages, clamped into range. */
    public function save(float $servicePercent, float $domainPercent): void
    {
        $this->settings->set(self::KEY_SERVICES, self::formatPercent($servicePercent));
        $this->settings->set(self::KEY_DOMAINS, self::formatPercent($domainPercent));
    }

    /**
     * 0–100, to two decimals. Anything outside that is nonsense as a discount:
     * a negative figure would charge a reseller MORE than list price, and over
     * 100 would pay them to order.
     */
    public static function clampPercent(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }

        return $value > 100.0 ? 100.0 : round($value, 2);
    }

    public static function formatPercent(float $value): string
    {
        return number_format(self::clampPercent($value), 2, '.', '');
    }

    private static function keyFor(string $kind): string
    {
        return $kind === 'domain' ? self::KEY_DOMAINS : self::KEY_SERVICES;
    }
}
