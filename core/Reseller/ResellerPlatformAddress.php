<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\Settings\SettingsRepository;

/**
 * The domain reseller stores get their free address under: with `resellerhub.com`
 * set here, the store whose slug is `acme` is served at `acme.resellerhub.com`.
 *
 * WHY THIS IS A SETTING AND NOT APP_URL
 *
 * Store addresses used to be built under the platform's own host (APP_URL). When
 * the client area itself lives on a subdomain, e.g. client.example.com, that made
 * every store a sub-subdomain (acme.client.example.com). That address is too long,
 * shows the host's brand, and is not covered by a normal `*.example.com` wildcard
 * certificate, so HTTPS fails on it. The super admin now picks a separate domain,
 * ideally a neutral one, that is wildcard-pointed at this server.
 *
 * NOT SET MEANS NOT SERVED
 *
 * With no domain configured, a store has no free address at all. Its slug is just
 * its name, and the store is reachable only on its own custom domain once that is
 * verified. No address is quietly made up under the platform's host.
 */
final class ResellerPlatformAddress
{
    public const KEY = 'reseller.platform_domain';

    private bool $loaded = false;
    private ?string $domain = null;

    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    /** The configured domain (normalised), or null when none is set. */
    public function domain(): ?string
    {
        if (!$this->loaded) {
            $this->loaded = true;

            try {
                $this->domain = self::normalise((string) ($this->settings->get(self::KEY, '') ?? ''))['domain'];
            } catch (\Throwable) {
                // A settings read must never take a storefront down: unreadable
                // means "not configured", the safe answer.
                $this->domain = null;
            }
        }

        return $this->domain;
    }

    /** Store a domain already checked by normalise(); null clears it. */
    public function save(?string $domain): void
    {
        $this->settings->set(self::KEY, $domain ?? '');
        $this->domain = $domain;
        $this->loaded = true;
    }

    /**
     * Turn what an admin typed into one canonical domain, or explain why not.
     *
     * Forgiving about what people paste ("https://Example.com/", "*.example.com",
     * "example.com:443") and strict about what it accepts. The result must be a real
     * multi-label DNS name with a non-numeric TLD, because every store address is
     * built from it. An empty input is valid and means "clear the setting".
     *
     * @return array{domain: ?string, error: ?string}
     */
    public static function normalise(string $input): array
    {
        $value = strtolower(trim($input));

        if ($value === '') {
            return ['domain' => null, 'error' => null];
        }

        $value = (string) preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $value);
        $value = (string) preg_replace('~[/?#].*$~', '', $value);
        $value = (string) preg_replace('~:\d+$~', '', $value);
        $value = (string) preg_replace('~^\*\.~', '', $value);
        $value = rtrim($value, '.');

        $invalid = ['domain' => null, 'error' => 'Enter a domain name like resellerhub.com: letters, digits, hyphens and dots only, without http:// or a path.'];

        if ($value === '' || strlen($value) > 253 || filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return $invalid;
        }

        $labels = explode('.', $value);

        if (count($labels) < 2) {
            return $invalid;
        }

        foreach ($labels as $label) {
            if (preg_match('~^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$~', $label) !== 1) {
                return $invalid;
            }
        }

        if (preg_match('~^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$~', (string) end($labels)) !== 1) {
            return $invalid;
        }

        return ['domain' => $value, 'error' => null];
    }

    /** `{slug}.{domain}`, or null when there is no domain to build it under. */
    public static function addressFor(string $slug, ?string $domain): ?string
    {
        $slug = strtolower(trim($slug));

        return $domain === null || $domain === '' || $slug === '' ? null : $slug . '.' . $domain;
    }
}
