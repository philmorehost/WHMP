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
 *
 * THE SWITCH COVERS REMOVAL TOO, DELIBERATELY. Off means "this application does
 * not touch the hosting panel". A switch that added domains but refused to take
 * them away would be the worst of both, because the leftovers are the part
 * nobody can see. So when it is off a removal reports itself as SKIPPED with the
 * hostname named, rather than nothing appearing to happen.
 *
 * REMOVAL IS VERIFIED, ADDITION IS NOT. Both directions can fail quietly, but
 * only one of them is invisible in use: a domain that was never added does not
 * serve, and somebody notices within minutes. A domain that was never REMOVED
 * keeps answering for a hostname no store claims, which falls through to the
 * platform shop at platform prices — the tenant-isolation failure — and can stay
 * that way indefinitely. So `remove()` re-reads the account's addon domains
 * afterwards rather than trusting the reply.
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

        $target = $this->target($domain, 'add');

        if (!$target['ready']) {
            return ['ok' => false, 'skipped' => $target['skipped'], 'message' => $target['message']];
        }

        $result = $this->uapi->call($target['server'], $target['account'], 'AddonDomain', 'addaddondomain', [
            'newdomain' => $domain,
            'subdomain' => $this->subdomainLabel($domain),
            'dir' => ltrim($target['docroot'], '/'),
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
     * Take the domain back off the panel.
     *
     * One method for all four reasons it is ever needed — the reseller replaced
     * their domain, released it, an admin refused it, or the account was deleted
     * outright. They are the same operation, and four call sites each doing their
     * own removal is how one of them ends up subtly different.
     *
     * @return array{ok: bool, skipped: bool, message: string}
     */
    public function remove(string $domain): array
    {
        $domain = ResellerStoreLocator::normaliseHost($domain);

        if ($domain === '') {
            return $this->fail('There is no domain name to remove.');
        }

        $target = $this->target($domain, 'remove');

        if (!$target['ready']) {
            return ['ok' => false, 'skipped' => $target['skipped'], 'message' => $target['message']];
        }

        $result = $this->uapi->call($target['server'], $target['account'], 'AddonDomain', 'deladdondomain', [
            'domain' => $domain,
            'subdomain' => $this->subdomainLabel($domain),
        ]);

        $message = trim((string) ($result['message'] ?? ''));

        if (!$result['success'] && !$this->looksMissing($message)) {
            return $this->fail($message !== '' ? $message : 'The hosting panel refused the request.');
        }

        // Verify rather than trust. A domain an administrator added by hand in
        // cPanel is NOT under our derived subdomain label, so the panel can
        // answer "does not exist" while the name is still listed and still
        // answering — the removal reporting success is precisely the failure
        // that would never be noticed.
        $stillListed = $this->stillListed($target['server'], $target['account'], $domain);

        if ($stillListed === true) {
            return $this->fail(
                $domain . ' is still on the hosting panel after the removal call'
                . ($message !== '' && !$result['success'] ? ' ("' . $message . '")' : '')
                . ' — remove it by hand under cPanel → Addon Domains.'
            );
        }

        return [
            'ok' => true,
            'skipped' => false,
            'message' => $domain . ($stillListed === null
                // Say so rather than implying we checked, and do not treat an
                // unreadable answer as proof of anything in either direction.
                ? ' was removed from the hosting panel, but the panel did not confirm it — worth a look under Addon Domains.'
                : ' was removed from the hosting panel.'),
        ];
    }

    /**
     * Resolve and check the panel target, so both directions refuse for the same
     * reasons and neither can send a half-formed request.
     *
     * @return array{ready: bool, skipped: bool, message: string, server: array<string, mixed>, account: string, docroot: string}
     */
    private function target(string $domain, string $action): array
    {
        if (!$this->enabled()) {
            return [
                'ready' => false,
                'skipped' => true,
                // Worded per direction: "point its DNS here and issue a
                // certificate" is meaningless advice for a domain being removed,
                // and a skipped removal is the one case where the message IS the
                // only thing that stops a hostname being forgotten — the row that
                // records it is about to disappear.
                'message' => $action === 'remove'
                    ? 'Automatic provisioning is off — ' . $domain . ' is still on the hosting panel and has to be '
                        . 'removed there by hand (see docs/RESELLER_DOMAIN_SETUP.md).'
                    : 'Automatic provisioning is off — add ' . $domain . ' to the hosting panel yourself, then point '
                        . 'its DNS here and issue a certificate (see docs/RESELLER_DOMAIN_SETUP.md).',
                'server' => [],
                'account' => '',
                'docroot' => '',
            ];
        }

        $serverId = (int) trim((string) $this->settings->get('reseller.cpanel_server_id', ''));
        $account = trim((string) $this->settings->get('reseller.cpanel_account_user', ''));
        $docroot = trim((string) $this->settings->get('reseller.cpanel_docroot', ''));

        // Refuse before calling out rather than sending a half-formed request:
        // a missing document root would otherwise silently park the domain on a
        // fresh empty folder, which looks like success and serves nothing.
        if ($serverId <= 0 || $account === '' || $docroot === '') {
            return [
                'ready' => false,
                'skipped' => false,
                'message' => 'Provisioning is switched on but not configured: set the server, the cPanel account and the '
                    . 'document root under Reseller store provisioning settings.',
                'server' => [],
                'account' => '',
                'docroot' => '',
            ];
        }

        $server = $this->servers->find($serverId);

        if ($server === null) {
            return [
                'ready' => false,
                'skipped' => false,
                'message' => 'The configured server (id ' . $serverId . ') no longer exists.',
                'server' => [],
                'account' => '',
                'docroot' => '',
            ];
        }

        return [
            'ready' => true,
            'skipped' => false,
            'message' => '',
            'server' => $server,
            'account' => $account,
            'docroot' => $docroot,
        ];
    }

    /**
     * Panel wording that means "it is not there". For a removal that is the
     * post-condition we wanted rather than an error — otherwise a retry, or a
     * domain an administrator already deleted by hand, would report failure
     * forever and block the step after it.
     */
    private function looksMissing(string $message): bool
    {
        foreach (['does not exist', 'not exist', 'not found', 'no such', 'no addon', 'nothing to delete'] as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ask the panel what it can actually do.
     *
     * This exists because a failure here is otherwise nearly undiagnosable: the
     * only symptom is an approval that records an error, and the interesting
     * information (which module file is missing, which API version answered,
     * whether the account is even reachable) is in the panel's reply, not in
     * anything the application knows.
     *
     * It deliberately reports the RAW replies. A "connection failed" summary
     * would hide the one detail that matters, which is usually the panel naming
     * a file it could not load.
     *
     * @return array<int, array{label: string, ok: ?bool, detail: string}>
     */
    public function diagnose(): array
    {
        $rows = [];

        $mode = $this->mode();
        $rows[] = [
            'label' => 'Automatic provisioning',
            'ok' => $mode === self::MODE_CPANEL,
            'detail' => $mode === self::MODE_CPANEL
                ? 'On — approvals will call the hosting panel.'
                : 'Off — nothing is sent to the panel, and domains must be added and removed by hand.',
        ];

        $serverId = (int) trim((string) $this->settings->get('reseller.cpanel_server_id', ''));
        $account = trim((string) $this->settings->get('reseller.cpanel_account_user', ''));
        $docroot = trim((string) $this->settings->get('reseller.cpanel_docroot', ''));

        $rows[] = [
            'label' => 'Settings',
            'ok' => $serverId > 0 && $account !== '' && $docroot !== '',
            'detail' => 'server #' . ($serverId > 0 ? $serverId : '(none)')
                . ', cPanel account "' . ($account !== '' ? $account : '(none)') . '"'
                . ', document root "' . ($docroot !== '' ? $docroot : '(none)') . '"',
        ];

        if ($serverId <= 0) {
            return $rows;
        }

        $server = $this->servers->find($serverId);

        if ($server === null) {
            $rows[] = ['label' => 'WHM server', 'ok' => false, 'detail' => 'Server #' . $serverId . ' no longer exists.'];

            return $rows;
        }

        $rows[] = [
            'label' => 'WHM server',
            'ok' => true,
            'detail' => (string) $server['hostname'] . ':' . (string) ($server['api_port'] ?? 2087),
        ];

        // A reachability check the WHM API 1 `version` function answers, which is
        // the one cPanel call this codebase has confirmed against a live server.
        $version = $this->uapi->callWhm($server, 'version');
        $rows[] = [
            'label' => 'WHM reachable',
            'ok' => $version['success'],
            'detail' => $version['success']
                ? 'Yes — ' . (string) ($version['data']['version'] ?? 'version not reported')
                : (string) $version['message'],
        ];

        if ($account === '') {
            return $rows;
        }

        // The decisive check. It exercises the SAME module and API-version
        // fallback the approval uses, so a red row here is exactly why an
        // approval failed — reported here with the panel's own wording.
        $list = $this->listResult($server, $account);
        $addons = $list['success'] ? $this->addonRows($list['data'] ?? null) : null;

        $rows[] = [
            'label' => 'Addon domains readable',
            'ok' => $list['success'] && $addons !== null,
            'detail' => $list['success']
                ? ($addons === null
                    ? 'The panel answered, but the list was not in a shape we recognise. Raw: ' . $this->shorten($list['data'])
                    : 'Yes, over API ' . (string) ($list['api_version'] ?? '?') . ' — this account currently has '
                        . count($addons) . ': '
                        . ($addons === []
                            ? 'none'
                            : implode(', ', array_map(static fn (array $row): string => $row['domain'], $addons))))
                : (string) $list['message'],
        ];

        if ($addons === null || $addons === []) {
            return $rows;
        }

        // The failure this catches LOOKS LIKE SUCCESS: the domain was created and
        // the panel parked it on a folder with no application in it, so the address
        // answers with the panel's own error page instead of the store. Nothing in
        // the application can read the account's filesystem, so the panel's own idea
        // of each document root is the only evidence available.
        //
        // Matched by SUFFIX, not substring, and that is the whole point: a domain
        // parked on `/home/user/public_html/domain.example.com` CONTAINS
        // "public_html", so a substring test would call it correct. It has to END
        // with the configured folder to be serving the same files.
        $expected = rtrim(str_replace('\\', '/', trim($docroot)), '/');
        $mismatched = [];
        $unknown = [];

        foreach ($addons as $addon) {
            if ($addon['root'] === null) {
                $unknown[] = $addon['domain'];

                continue;
            }

            $root = rtrim(str_replace('\\', '/', (string) $addon['root']), '/');

            if ($expected !== '' && $root !== $expected && !str_ends_with($root, '/' . $expected)) {
                $mismatched[] = $addon['domain'] . ' → ' . $root;
            }
        }

        $rows[] = [
            'label' => 'Domains serve this application',
            'ok' => $mismatched !== [] ? false : ($unknown !== [] ? null : true),
            'detail' => $mismatched !== []
                ? 'These are NOT serving this application — the panel put them in a different folder. Change their '
                    . 'document root to "' . $docroot . '" (cPanel → Domains), the folder that serves this platform: '
                    . implode('; ', $mismatched)
                : ($unknown !== []
                    ? 'The panel did not report a document root for: ' . implode(', ', $unknown)
                        . '. Compare each with "' . $docroot . '" under cPanel → Domains.'
                    : 'Every addon domain ends at ' . $docroot . '.'),
        ];

        return $rows;
    }

    /** Collapse a raw panel payload into something that fits on one line. */
    private function shorten(mixed $data): string
    {
        $json = is_string($data) ? $data : (string) json_encode($data);

        return strlen($json) > 300 ? substr($json, 0, 300) . '…' : $json;
    }

    /**
     * The addon-domain listing, in one place so the removal check and the
     * diagnostic can never disagree about what the account holds.
     *
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    private function listResult(array $server, string $account): array
    {
        return $this->uapi->call($server, $account, 'AddonDomain', 'listaddondomains', []);
    }

    /**
     * Is the domain still on the panel? TRUE / FALSE, or NULL when the panel's
     * answer could not be read.
     *
     * NULL is not "no". It is reported to the administrator as an UNCONFIRMED
     * removal, because reading an unreadable answer as success is exactly how a
     * leftover hostname stays on the server unnoticed. Same rule the payment
     * webhooks follow: an unreadable outcome is not a failed outcome, and it is
     * certainly not a successful one.
     *
     * @param array<string, mixed> $server
     */
    private function stillListed(array $server, string $account, string $domain): ?bool
    {
        $result = $this->listResult($server, $account);

        if (!$result['success']) {
            return null;
        }

        $hosts = $this->addonHosts($result['data'] ?? null);

        if ($hosts === null) {
            return null;
        }

        return in_array($domain, $hosts, true);
    }

    /**
     * Pull the addon domains out of a listing payload, or NULL if the shape is not
     * one we recognise. Unwraps the `payload` / `data` envelopes cPanel varies
     * between versions before looking for rows.
     *
     * The document root is carried because it is the difference between a working
     * domain and one sitting on an empty folder. Different cPanel versions call it
     * different things and we cannot see the account's filesystem to work it out, so
     * it is NULL when the panel did not name it rather than guessed — a wrong
     * "matches" verdict here would be worse than no verdict.
     *
     * @return array<int, array{domain: string, root: ?string}>|null
     */
    private function addonRows(mixed $data): ?array
    {
        if (!is_array($data)) {
            return null;
        }

        foreach (['payload', 'data'] as $key) {
            if (is_array($data[$key] ?? null)) {
                return $this->addonRows($data[$key]);
            }
        }

        $rows = [];

        foreach ($data as $row) {
            if (!is_array($row)) {
                return null;
            }

            $host = $row['domain'] ?? $row['addon_domain'] ?? null;

            if (!is_string($host) || trim($host) === '') {
                return null;
            }

            $root = null;

            foreach (['documentroot', 'docroot', 'reldir', 'dir', 'basedir'] as $key) {
                $candidate = $row[$key] ?? null;

                if (is_string($candidate) && trim($candidate) !== '') {
                    $root = trim($candidate);

                    break;
                }
            }

            $rows[] = ['domain' => ResellerStoreLocator::normaliseHost($host), 'root' => $root];
        }

        return $rows;
    }

    /**
     * Just the hostnames from the same listing.
     *
     * @return array<int, string>|null
     */
    private function addonHosts(mixed $data): ?array
    {
        $rows = $this->addonRows($data);

        return $rows === null
            ? null
            : array_map(static fn (array $row): string => $row['domain'], $rows);
    }

    /**
     * A subdomain label cPanel will accept, derived from the domain so a retry
     * asks for the same one, but suffixed to make an accidental clash with an
     * existing subdomain very unlikely.
     *
     * MUST stay a pure function of the domain. cPanel keys `deladdondomain` on
     * this label, so changing the derivation would strand every domain already on
     * the panel — unremovable, and answering as the platform shop.
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
