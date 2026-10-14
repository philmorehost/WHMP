<?php

declare(strict_types=1);

namespace CodeVault\Cloudflare;

use CodeVault\Provisioning\CurlHttpClient;
use CodeVault\Provisioning\HttpClient;
use CodeVault\Security\SecretBox;
use CodeVault\Settings\SettingsRepository;

/**
 * Add-on settings (key/value in `settings`, prefix `cloudflare.`). The API token is
 * stored encrypted with SecretBox and never rendered back to a page.
 */
final class CloudflareSettings
{
    public const PREFIX = 'cloudflare.';

    public const SSL_MODES = ['off', 'flexible', 'full', 'strict'];
    public const SECURITY_LEVELS = ['essentially_off', 'low', 'medium', 'high', 'under_attack'];

    public const DEFAULTS = [
        'api_token' => '',
        'account_id' => '',
        'account_name' => '',
        'accounts_cache' => '[]',
        'default_ssl' => 'full',
        'default_always_https' => '1',
        'default_security_level' => 'medium',
        'grace_days' => '7',
        'option_group_id' => '0',
        'option_yes_id' => '0',
        'allow_later' => '1',
    ];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ?SecretBox $secrets = null,
        private readonly ?HttpClient $http = null
    ) {
    }

    public function raw(string $key): string
    {
        return (string) ($this->settings->get(self::PREFIX . $key, self::DEFAULTS[$key] ?? '') ?? (self::DEFAULTS[$key] ?? ''));
    }

    /** @param array<string, scalar> $values */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (array_key_exists($key, self::DEFAULTS) && $key !== 'api_token') {
                $this->settings->set(self::PREFIX . $key, (string) $value);
            }
        }
    }

    public function saveToken(string $token): void
    {
        $token = trim($token);
        $stored = $token === '' ? '' : ($this->secrets?->available() ? $this->secrets->encrypt($token) : $token);
        $this->settings->set(self::PREFIX . 'api_token', $stored);
    }

    public function token(): string
    {
        $stored = $this->raw('api_token');

        if ($stored === '') {
            return '';
        }

        return $this->secrets !== null ? (string) ($this->secrets->decrypt($stored) ?? '') : $stored;
    }

    public function connected(): bool
    {
        return $this->token() !== '' && $this->accountId() !== '';
    }

    public function accountId(): string
    {
        return $this->raw('account_id');
    }

    public function accountName(): string
    {
        return $this->raw('account_name');
    }

    /** @return array<int, array{id: string, name: string}> accounts the token can see (from the last Verify) */
    public function accountsCache(): array
    {
        $decoded = json_decode($this->raw('accounts_cache'), true);

        return is_array($decoded) ? array_values(array_filter($decoded, static fn ($a): bool => is_array($a) && isset($a['id'], $a['name']))) : [];
    }

    public function defaultSsl(): string
    {
        $v = $this->raw('default_ssl');

        return in_array($v, self::SSL_MODES, true) ? $v : 'full';
    }

    public function defaultAlwaysHttps(): bool
    {
        return $this->raw('default_always_https') === '1';
    }

    public function defaultSecurityLevel(): string
    {
        $v = $this->raw('default_security_level');

        return in_array($v, self::SECURITY_LEVELS, true) ? $v : 'medium';
    }

    public function graceDays(): int
    {
        return max(1, min(90, (int) $this->raw('grace_days')));
    }

    public function optionGroupId(): int
    {
        return (int) $this->raw('option_group_id');
    }

    public function optionYesId(): int
    {
        return (int) $this->raw('option_yes_id');
    }

    /** Clients on an eligible product may switch Cloudflare on after ordering too. */
    public function allowLater(): bool
    {
        return $this->raw('allow_later') === '1';
    }

    public function api(?string $token = null): CloudflareApi
    {
        return new CloudflareApi($this->http ?? new CurlHttpClient(timeoutSeconds: 20), $token ?? $this->token());
    }
}
