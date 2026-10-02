<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\CpanelTools\CpanelUapiClient;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Settings\SettingsRepository;

/**
 * Asks the hosting panel to serve a store's custom domain.
 *
 * This is the ONE piece of the storefront that reaches outside the application
 * and touches the web server, and it exists because the alternative is an admin
 * doing it by hand in cPanel for every reseller. It reuses the existing WHM
 * integration (`CpanelUapiClient`, the same client the client-area cPanel tools
 * use) rather than adding a second way to talk to the panel.
 *
 * WHY IT IS OFF BY DEFAULT
 *
 * It writes to a hosting panel on behalf of somebody else's domain, using
 * credentials that belong to this install's own account. Getting that wrong adds
 * a domain to the wrong account. So `reseller.domain_provisioning` defaults to
 * 'off', and when it is off an approval is simply recorded — the admin adds the
 * domain themselves, exactly as before. Turning it on is a deliberate act by
 * someone who has filled in the three settings below and checked them.
 *
 * WHAT IT NEEDS (all on the store-provisioning settings, `/admin/resellers/domains`)
 *
 *   reseller.cpanel_server_id      which row of `servers` is THIS platform's own
 *                                  cPanel account (its hostname + WHM API token)
 *   reseller.cpanel_account_user   the cPanel account to add the domain to —
 *                                  normally this platform's own account
 *   reseller.cpanel_docroot        the document root, RELATIVE TO THE ACCOUNT'S
 *                                  HOME, that already serves this platform, e.g.
 *                                  `public_html/whm/public`. The domain is pointed
 *                                  at the same folder so the store is served by
 *                                  the same application, not a copy of it.
 *
 * None of that can be guessed from inside the application, which is why it is
 * settings rather than constants.
 *
 * FAILURE IS NOT FATAL AND NOT SILENT. Every outcome is returned to the caller,
 * which records it on the store (`domain_provision_error`). An approval that the
 * panel refused is a state the admin has to fix, and it must be visible rather
 * than looking like an approval that worked.
 */
final class ResellerDomainProvisioner
{
    public const MODE_OFF = 'off';
    public const MODE_CPANEL = 'cpanel';

    public function __construct(
        private readonly CpanelUapiClient $uapi,
        private readonly ServerRepository $servers,
        private readonly SettingsRepository $settings
    ) {
    }

    /** Whether automatic provisioning is switched on at all. */
    public function enabled(): bool
    {
        return $this->mode() === self::MODE_CPANEL;
    }

    public function mode(): string
    {
        $mode = strtolower(trim((string) $this->settings->get('reseller.domain_provisioning', self::MODE_OFF)));

        return in_array($mode, [self::MODE_OFF, self::MODE_CPANEL], true) ? $mode : self::MODE_OFF;
    }

    /**
     * Add the domain to the panel so it is served by this platform.
     *
     * @param array<string, mixed> $store
     * @return array{ok: bool, skipped: bool, message: string}
     */
    public function provision(array $store): array
    {
        $domain = ResellerStoreLocator::normaliseHost((string) ($store['custom_domain'] ?? ''));

        if ($domain === '') {
            return $this->fail('That store has no custom domain.');
        }

        if (!$this->enabled()) {
            return [
                'ok' => false,
                'skipped' => true,
                'message' => 'Automatic provisioning is off — add ' . $domain
                    . ' to the hosting panel yourself, then point its DNS here and issue a certificate '
                    . '(see docs/RESELLER_DOMAIN_SETUP.md).',
            ];
        }

        $serverId = (int) trim((string) $this->settings->get('reseller.cpanel_server_id', ''));
        $account = trim((string) $this->settings->get('reseller.cpanel_account_user', ''));
        $docroot = trim((string) $this->settings->get('reseller.cpanel_docroot', ''));

        // Refuse before calling out rather than sending a half-formed request:
        // a missing document root would otherwise silently park the domain on a
        // fresh empty folder, which looks like success and serves nothing.
        if ($serverId <= 0 || $account === '' || $docroot === '') {
            return $this->fail(
                'Provisioning is switched on but not configured: set the server, the cPanel account and the '
                . 'document root under Reseller store provisioning settings.'
            );
        }

        $server = $this->servers->find($serverId);

        if ($server === null) {
            return $this->fail('The configured server (id ' . $serverId . ') no longer exists.');
        }

        $result = $this->uapi->call($server, $account, 'AddonDomain', 'addaddondomain', [
            'newdomain' => $domain,
            'subdomain' => $this->subdomainLabel($domain),
            'dir' => ltrim($docroot, '/'),
        ]);

        $message = (string) ($result['message'] ?? '');

        // "Already exists" is success for our purposes: the domain is on the
        // panel, which is the post-condition we wanted, and re-approving (or a
        // retry after a timeout) must not look like a failure.
        if (!$result['success'] && stripos($message, 'already exist') !== false) {
            return ['ok' => true, 'skipped' => false, 'message' => $domain . ' was already on the panel.'];
        }

        if (!$result['success']) {
            return $this->fail(trim($message) !== '' ? $message : 'The hosting panel refused the request.');
        }

        return [
            'ok' => true,
            'skipped' => false,
            'message' => $domain . ' added to the hosting panel. Point its DNS here and issue a certificate next '
                . '(cPanel: SSL/TLS Status → Run AutoSSL).',
        ];
    }

    /**
     * A subdomain label cPanel will accept, derived from the domain so a retry
     * asks for the same one, but suffixed to make an accidental clash with an
     * existing subdomain very unlikely.
     */
    private function subdomainLabel(string $domain): string
    {
        return 'rv' . substr(sha1($domain), 0, 10);
    }

    /** @return array{ok: bool, skipped: bool, message: string} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'skipped' => false, 'message' => $message];
    }
}
