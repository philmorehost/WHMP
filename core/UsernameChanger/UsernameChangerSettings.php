<?php

declare(strict_types=1);

namespace CodeVault\UsernameChanger;

use CodeVault\Settings\SettingsRepository;

/**
 * The global settings (`settings` rows prefixed `username_changer.`), each with
 * the default from plan §3 / §14. Every getter clamps to a safe range, so a
 * hand-edited settings row can never produce, say, a 40-character username or
 * a negative fee.
 */
final class UsernameChangerSettings
{
    public const PREFIX = 'username_changer.';

    /** @var array<string, string> */
    public const DEFAULTS = [
        'min_length' => '5',
        'max_length' => '16',
        'reserved_extra' => '',
        'unique_scope' => 'platform',
        'max_changes' => '1',
        'cooldown_days' => '30',
        'statuses' => 'active',
        'product_types' => 'shared,reseller',
        'approval' => 'none',
        'confirm_methods' => 'email,pin',
        'confirm_ttl_hours' => '48',
        'execution' => 'immediate',
        'max_attempts' => '3',
        'allow_db_rename' => '0',
        'require_reason' => '0',
        'retention_days' => '365',
        'stores_allowed' => '1',
        'store_approval_allowed' => '1',
        'staff_alert_email' => '',
        'first8_rule' => 'auto',
        'heading' => 'Change cPanel username',
        'accent' => '',
        // Payment (off by default — the change is free until the super admin says otherwise).
        'fee_enabled' => '0',
        'fee' => '0.00',
        'store_pricing' => '1',
    ];

    /** @var array<string, string>|null */
    private ?array $memo = null;

    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function raw(string $key): string
    {
        if ($this->memo !== null && array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $value = (string) ($this->settings->get(self::PREFIX . $key, self::DEFAULTS[$key] ?? '') ?? (self::DEFAULTS[$key] ?? ''));
        $this->memo[$key] = $value;

        return $value;
    }

    /** @param array<string, string> $values */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (array_key_exists($key, self::DEFAULTS)) {
                $this->settings->set(self::PREFIX . $key, (string) $value);
                $this->memo[$key] = (string) $value;
            }
        }
    }

    public function policy(): UsernamePolicy
    {
        return new UsernamePolicy($this->minLength(), $this->maxLength(), $this->reservedExtra());
    }

    public function minLength(): int
    {
        return self::clamp((int) $this->raw('min_length'), 1, 16);
    }

    public function maxLength(): int
    {
        return self::clamp((int) $this->raw('max_length'), max(1, $this->minLength()), 16);
    }

    /** @return array<int, string> */
    public function reservedExtra(): array
    {
        $words = preg_split('/[\s,]+/', strtolower($this->raw('reserved_extra'))) ?: [];

        return array_values(array_filter($words, static fn ($w) => $w !== '' && preg_match('/^[a-z0-9]+$/', $w)));
    }

    /** 'platform' (unique across every server) | 'server' (unique per server). */
    public function uniqueScope(): string
    {
        return $this->raw('unique_scope') === 'server' ? 'server' : 'platform';
    }

    /** 0 = unlimited. */
    public function maxChanges(): int
    {
        return self::clamp((int) $this->raw('max_changes'), 0, 1000);
    }

    public function cooldownDays(): int
    {
        return self::clamp((int) $this->raw('cooldown_days'), 0, 3650);
    }

    /** @return array<int, string> */
    public function statuses(): array
    {
        return self::list($this->raw('statuses'), ['pending', 'active', 'suspended'], ['active']);
    }

    /** @return array<int, string> */
    public function productTypes(): array
    {
        return self::list($this->raw('product_types'), ['shared', 'reseller', 'vps', 'dedicated', 'other'], ['shared', 'reseller']);
    }

    /** 'none' | 'admin' */
    public function approval(): string
    {
        return $this->raw('approval') === 'admin' ? 'admin' : 'none';
    }

    /** @return array<int, string> */
    public function confirmMethods(): array
    {
        return self::list($this->raw('confirm_methods'), ['email', 'pin'], ['email']);
    }

    public function confirmTtlHours(): int
    {
        return self::clamp((int) $this->raw('confirm_ttl_hours'), 1, 720);
    }

    /** 'immediate' | 'queued' */
    public function execution(): string
    {
        return $this->raw('execution') === 'queued' ? 'queued' : 'immediate';
    }

    public function maxAttempts(): int
    {
        return self::clamp((int) $this->raw('max_attempts'), 1, 10);
    }

    public function allowDbRename(): bool
    {
        return $this->raw('allow_db_rename') === '1';
    }

    public function requireReason(): bool
    {
        return $this->raw('require_reason') === '1';
    }

    public function retentionDays(): int
    {
        return self::clamp((int) $this->raw('retention_days'), 7, 3650);
    }

    public function storesAllowed(): bool
    {
        return $this->raw('stores_allowed') !== '0';
    }

    public function storeApprovalAllowed(): bool
    {
        return $this->raw('store_approval_allowed') !== '0';
    }

    public function staffAlertEmail(): string
    {
        $email = trim($this->raw('staff_alert_email'));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /** 'auto' | 'on' | 'off' — the MySQL "first 8 characters unique" rule. */
    public function first8Rule(): string
    {
        $v = $this->raw('first8_rule');

        return in_array($v, ['on', 'off'], true) ? $v : 'auto';
    }

    public function heading(): string
    {
        $h = trim($this->raw('heading'));

        return $h !== '' ? mb_substr($h, 0, 80) : 'Change cPanel username';
    }

    /** A #rrggbb accent or '' for the theme's own. */
    public function accent(): string
    {
        $a = trim($this->raw('accent'));

        return preg_match('/^#[0-9a-fA-F]{6}$/', $a) ? $a : '';
    }

    /** Payment acceptance — the super admin's ON/OFF switch. */
    public function feeEnabled(): bool
    {
        return $this->raw('fee_enabled') === '1';
    }

    /** The super admin's fee, in the catalog (pricing) currency. */
    public function fee(): float
    {
        return max(0.0, round((float) $this->raw('fee'), 2));
    }

    /** Whether resellers may resell the change at their own (higher) price. */
    public function storePricing(): bool
    {
        return $this->raw('store_pricing') !== '0';
    }

    private static function clamp(int $v, int $min, int $max): int
    {
        return max($min, min($max, $v));
    }

    /**
     * @param array<int, string> $allowed
     * @param array<int, string> $fallback
     * @return array<int, string>
     */
    private static function list(string $raw, array $allowed, array $fallback): array
    {
        $items = array_values(array_intersect($allowed, array_map('trim', explode(',', strtolower($raw)))));

        return $items === [] ? $fallback : $items;
    }
}
