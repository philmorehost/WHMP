<?php

declare(strict_types=1);

namespace CodeVault\Cloudflare;

use CodeVault\Database;
use CodeVault\Hooks\HookPoints;
use CodeVault\Modules\AddonModule;
use CodeVault\Support\App;
use Throwable;

/**
 * Cloudflare CDN & Security (Free) — the on/off switch (Addons → Cloudflare).
 *
 * Off until the super admin activates it. While off, the client page, the
 * service-page card and every endpoint are gone (404), and no hook runs.
 * The working screens live on CloudflareAdminController (/admin/cloudflare);
 * this page summarises and links there.
 *
 * Built every request at boot, so it holds only light dependencies; the
 * service is resolved when a hook actually fires.
 */
final class CloudflareAddon implements AddonModule
{
    public function __construct(
        private readonly ?CloudflareZoneRepository $zones = null,
        private readonly ?CloudflareSettings $settings = null,
        private readonly ?Database $db = null
    ) {
    }

    public function metadata(): array
    {
        return [
            'name' => 'Cloudflare CDN & Security (Free)',
            'description' => 'Free Cloudflare for your customers\' domains — CDN, free SSL, DDoS protection and DNS — from your own Cloudflare account. Clients opt in at order (a free configurable option) or from the service page, switch nameservers in one click for domains registered with you, and manage DNS, SSL, caching and firewall rules themselves. Works on reseller stores, white-labelled.',
            'version' => '1.0.0',
            'author' => 'CodeVault',
        ];
    }

    public function configOptions(): array
    {
        return [];
    }

    /** @return array{success: bool, message: string} */
    public function activate(): array
    {
        try {
            if ($this->db !== null) {
                CloudflareTemplates::ensure($this->db);

                if ($this->settings !== null) {
                    (new CloudflareProductOption($this->db, $this->settings))->ensure();
                }
            }
        } catch (Throwable) {
            // Retried from the settings page.
        }

        return ['success' => true, 'message' => 'Cloudflare activated. Connect your Cloudflare account under Addons → Cloudflare → Settings, then choose which products offer it.'];
    }

    /** @return array{success: bool, message: string} */
    public function deactivate(): array
    {
        return ['success' => true, 'message' => 'Cloudflare deactivated — clients no longer see it. Existing zones stay in your Cloudflare account and keep working; re-activate to manage them again.'];
    }

    public function hooks(): array
    {
        return [
            HookPoints::SERVICE_STATUS_CHANGED => static function (array $payload): void {
                $serviceId = (int) ($payload['serviceId'] ?? 0);
                $status = (string) ($payload['status'] ?? '');

                if ($serviceId <= 0 || $status === '') {
                    return;
                }

                try {
                    App::container()->make(CloudflareService::class)->onServiceStatus($serviceId, $status);
                } catch (Throwable) {
                    // Never break provisioning.
                }
            },
        ];
    }

    public function render(array $params): string
    {
        $counts = ['active' => 0, 'pending' => 0, 'paused' => 0, 'deleting' => 0];
        $connected = false;
        $account = '';

        try {
            $counts = $this->zones?->counts() ?? $counts;
            $connected = $this->settings?->connected() ?? false;
            $account = $this->settings?->accountName() ?? '';
        } catch (Throwable) {
        }

        $status = $connected
            ? '<span class="cv-badge cv-badge--success">Connected</span> ' . e($account)
            : '<span class="cv-badge cv-badge--warning">Not connected</span> Add your API token in Settings.';

        return '<div class="cv-card" style="padding:1.25rem">'
            . '<p>' . $status . '</p>'
            . '<p><strong>' . (int) $counts['active'] . '</strong> active · <strong>' . (int) $counts['pending'] . '</strong> waiting for nameservers · <strong>' . (int) $counts['paused'] . '</strong> paused · <strong>' . (int) $counts['deleting'] . '</strong> scheduled for removal</p>'
            . '<p style="display:flex;gap:.5rem;flex-wrap:wrap">'
            . '<a class="cv-btn" href="/admin/cloudflare">Open dashboard</a>'
            . '<a class="cv-btn cv-btn--secondary" href="/admin/cloudflare/settings">Settings</a>'
            . '</p></div>';
    }
}
