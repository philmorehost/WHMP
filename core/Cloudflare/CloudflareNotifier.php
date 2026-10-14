<?php

declare(strict_types=1);

namespace CodeVault\Cloudflare;

use CodeVault\Config;
use CodeVault\Database;
use CodeVault\Mail\EmailDispatcher;
use CodeVault\Reseller\ResellerStoreLocator;
use CodeVault\Reseller\ResellerStoreRepository;
use CodeVault\Settings\SettingsRepository;
use Throwable;

/**
 * Client emails for the Cloudflare add-on. Best-effort: a mail failure never
 * fails a zone operation. A store customer's mail goes out under the store's
 * brand (EmailDispatcher) and links to the store's own address.
 */
final class CloudflareNotifier
{
    private bool $seeded = false;

    public function __construct(
        private readonly EmailDispatcher $mail,
        private readonly SettingsRepository $settings,
        private readonly Config $config,
        private readonly ?ResellerStoreRepository $stores = null,
        private readonly ?ResellerStoreLocator $locator = null,
        private readonly ?Database $db = null
    ) {
    }

    public function siteUrl(?int $storeId): string
    {
        if ($storeId !== null && $this->stores !== null && $this->locator !== null) {
            try {
                $store = $this->stores->find($storeId);

                if ($store !== null) {
                    return rtrim($this->locator->baseUrlFor($store), '/');
                }
            } catch (Throwable) {
            }
        }

        return rtrim((string) $this->config->env('APP_URL', 'http://localhost'), '/');
    }

    /**
     * @param array<string, mixed> $zone
     * @param array<string, mixed> $service ServiceRepository::find row
     */
    public function pending(array $zone, array $service, bool $registeredWithUs): void
    {
        $this->send(CloudflareTemplates::PENDING, $zone, $service, ['ns_hint' => self::nsHint($registeredWithUs)]);
    }

    /** @param array<string, mixed> $zone @param array<string, mixed> $service */
    public function active(array $zone, array $service): void
    {
        $this->send(CloudflareTemplates::ACTIVE, $zone, $service);
    }

    /** @param array<string, mixed> $zone @param array<string, mixed> $service */
    public function reminder(array $zone, array $service, bool $registeredWithUs): void
    {
        $this->send(CloudflareTemplates::REMINDER, $zone, $service, ['ns_hint' => self::nsHint($registeredWithUs)]);
    }

    /** @param array<string, mixed> $zone @param array<string, mixed> $service */
    public function removalScheduled(
        array $zone,
        array $service,
        string $reason,
        string $deleteAfter,
        bool $nsRestored,
        ?string $nsRestoreAfter = null,
        bool $dnssecHold = false
    ): void {
        $note = $nsRestored
            ? 'We have already put your domain\'s previous nameservers back, so your site keeps working without Cloudflare.'
            : ($nsRestoreAfter !== null
                ? 'We removed the registrar DS record. Your previous nameservers will be restored automatically after the DNSSEC cache wait, on ' . date('j M Y H:i', strtotime($nsRestoreAfter) ?: time()) . '.'
                : ($dnssecHold
                    ? 'DNSSEC needs extra care. Do not change nameservers away from Cloudflare yet; we will keep the zone serving until the registrar DS record and signing state are safe.'
                    : 'If your domain uses Cloudflare\'s nameservers, change them back to your hosting nameservers before that date so your site keeps working.'));
        $this->send(CloudflareTemplates::REMOVED, $zone, $service, [
            'reason' => $reason,
            'delete_date' => date('j M Y', strtotime($deleteAfter) ?: time()),
            'ns_note' => $note,
        ]);
    }

    private static function nsHint(bool $registeredWithUs): string
    {
        return $registeredWithUs
            ? 'Your domain is registered with us, so you can switch with one click from your client area.'
            : 'Make this change where your domain is registered (your registrar\'s control panel). It usually takes effect within a few hours.';
    }

    /**
     * @param array<string, mixed>  $zone
     * @param array<string, mixed>  $service
     * @param array<string, string> $extra
     */
    private function send(string $template, array $zone, array $service, array $extra = []): void
    {
        $email = (string) ($service['client_email'] ?? '');

        if ($email === '') {
            return;
        }

        try {
            if (!$this->seeded && $this->db !== null) {
                CloudflareTemplates::ensure($this->db);
                $this->seeded = true;
            }

            $storeId = ($service['client_reseller_id'] ?? null) === null ? null : (int) $service['client_reseller_id'];
            $ns = (array) ($zone['name_servers'] ?? []);
            $vars = [
                'client_name' => trim((string) ($service['first_name'] ?? '') . ' ' . (string) ($service['last_name'] ?? '')) ?: 'there',
                'domain' => (string) $zone['name'],
                'ns1' => (string) ($ns[0] ?? ''),
                'ns2' => (string) ($ns[1] ?? ''),
                'manage_url' => $this->siteUrl($storeId) . '/client/services/' . (int) $service['id'] . '/cloudflare',
                'company_name' => (string) ($this->settings->get('theme.brand_name', 'CodeVault') ?: 'CodeVault'),
            ] + $extra;

            $out = [];

            foreach ($vars as $key => $value) {
                $out[$key] = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            }

            $this->mail->sendTemplate($template, $email, $out, (int) $service['client_id']);
        } catch (Throwable) {
            // Best-effort.
        }
    }
}
