<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

use CodeVault\CpanelTools\CpanelUapiClient;
use CodeVault\Provisioning\ServerRepository;
use CodeVault\Settings\SettingsRepository;

/**
 * Creates a mailbox on a store's own domain, so the store can send as itself.
 *
 * WHY THIS EXISTS RATHER THAN A "FROM ADDRESS" TEXT FIELD
 *
 * ResellerMailIdentity will happily put a store's address on its customer mail. What it
 * cannot do is make that address REAL: an address nobody has created is one the reseller
 * cannot read — and that matters more than it first appears, because SmtpMailer sets
 * Reply-To to the same address. So a store that sends as `support@shop.example` and has no
 * such mailbox sends mail that customers reply to and nobody ever receives. Creating the
 * mailbox is what makes the identity honest rather than decorative.
 *
 * THE DOMAIN IS ALREADY HOSTED HERE, AND THAT IS THE WHOLE POINT
 *
 * A store's custom domain is added to THIS platform's cPanel account as an addon domain —
 * that is how the storefront is served at all. So the mailbox goes on the same account,
 * and on a cPanel host Exim signs outgoing mail with the domain's own DKIM key on the way
 * out. That signing relationship is what a mailbox at an unrelated provider would not have,
 * and it is the difference between mail that authenticates as the store and mail that
 * lands in a spam folder.
 *
 * THE PASSWORD IS SHOWN ONCE AND STORED NOWHERE
 *
 * The reseller needs it to read the inbox; nothing in this application needs it, because
 * we hand mail to the local MTA rather than logging in to the mailbox to send. So it is
 * generated, returned for one display, and never written down — the same posture as the
 * reseller API secret. If it is lost, it is reset in cPanel, which is also what an admin
 * would do for a forgotten mailbox password anyway.
 *
 * ONE SWITCH, REUSED
 *
 * `reseller.domain_provisioning` means "this application does not touch the hosting panel".
 * A second switch for mail would be a second thing to leave off and a second way for the
 * two to disagree, so this reads the same setting. It does NOT require `cpanel_docroot`,
 * because a mailbox is not served from a folder — demanding one would refuse to create
 * mail on an install that was configured for mail alone.
 */
final class ResellerMailboxProvisioner
{
    public const DEFAULT_LOCAL_PART = 'support';

    /**
     * 1 GB. Enough for a support inbox with attachments, and small enough that one store
     * cannot fill the account's disk. cPanel treats 0 as UNLIMITED, which is the default
     * this deliberately does not use: an unbounded mailbox on somebody else's behalf is a
     * problem discovered by the whole server, not by the reseller.
     */
    public const DEFAULT_QUOTA_MB = 1024;

    public function __construct(
        private readonly CpanelUapiClient $uapi,
        private readonly ServerRepository $servers,
        private readonly SettingsRepository $settings
    ) {
    }

    /** Whether we are allowed to touch the hosting panel at all. */
    public function enabled(): bool
    {
        return strtolower(trim((string) $this->settings->get('reseller.domain_provisioning', 'off'))) === 'cpanel';
    }

    /** The mailbox name in front of the @. */
    public function localPart(): string
    {
        $configured = trim((string) $this->settings->get('reseller.mailbox_local_part', ''));

        if ($configured === '') {
            return self::DEFAULT_LOCAL_PART;
        }

        // Anything that could not be a mailbox name falls back rather than being sent to
        // the panel, where it would come back as an opaque error.
        return preg_match('/^[a-z0-9][a-z0-9._-]{0,62}$/i', $configured) === 1
            ? strtolower($configured)
            : self::DEFAULT_LOCAL_PART;
    }

    public function quotaMb(): int
    {
        $configured = (int) trim((string) $this->settings->get('reseller.mailbox_quota_mb', ''));

        return $configured > 0 ? $configured : self::DEFAULT_QUOTA_MB;
    }

    /**
     * Create the store's mailbox.
     *
     * @param array<string, mixed> $store
     * @return array{ok: bool, skipped: bool, message: string, address: ?string, password: ?string}
     */
    public function provision(array $store, ?string $localPart = null): array
    {
        $domain = ResellerStoreLocator::normaliseHost((string) ($store['custom_domain'] ?? ''));

        if ($domain === '') {
            return $this->fail('This store has no domain of its own yet, so there is nothing to create a mailbox on.');
        }

        // A mailbox can only exist on a domain the panel actually hosts. Comparing the
        // PROVISIONED hostname catches the case where a store has just changed its domain:
        // asking the panel for a mailbox on the new one would either fail obscurely or, on
        // a panel that auto-creates, quietly build mail for a domain nobody serves.
        $provisioned = ResellerStoreLocator::normaliseHost((string) ($store['domain_provisioned_host'] ?? ''));

        if ($provisioned === '' || $provisioned !== $domain) {
            return $this->fail(
                $domain . ' is not on the hosting panel yet. Add it on the Domains page first — a mailbox can only '
                . 'exist on a domain the panel serves.'
            );
        }

        $target = $this->target();

        if (!$target['ready']) {
            return ['ok' => false, 'skipped' => $target['skipped'], 'message' => $target['message'], 'address' => null, 'password' => null];
        }

        $name = $localPart !== null ? strtolower(trim($localPart)) : $this->localPart();

        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,62}$/', $name) !== 1) {
            return $this->fail('That is not a usable mailbox name.');
        }

        $password = self::generatePassword();

        $result = $this->uapi->call($target['server'], $target['account'], 'Email', 'add_pop', [
            'email' => $name,
            'domain' => $domain,
            'password' => $password,
            'quota' => $this->quotaMb(),
        ]);

        $address = $name . '@' . $domain;
        $message = (string) ($result['message'] ?? '');

        // "Already exists" is the post-condition we wanted, not a failure — a retry, or a
        // mailbox an admin made by hand, must not read as broken. The password is NOT
        // returned in that case: this one we generated was not the one applied, and showing
        // it would hand the reseller a credential that does not work.
        if (!$result['success']) {
            if (stripos($message, 'already exist') !== false) {
                return [
                    'ok' => true,
                    'skipped' => false,
                    'message' => $address . ' already exists on the panel.',
                    'address' => $address,
                    'password' => null,
                ];
            }

            return [
                'ok' => false,
                'skipped' => false,
                'message' => $message !== '' ? $message : 'The hosting panel refused to create the mailbox.',
                'address' => null,
                'password' => null,
            ];
        }

        return [
            'ok' => true,
            'skipped' => false,
            'message' => $address . ' is ready.',
            'address' => $address,
            'password' => $password,
        ];
    }

    /**
     * A password cPanel will accept first time.
     *
     * Its strength checks reject single-character-class passwords, so four of each is not
     * decoration — it is what stops a "created successfully" call from coming back with a
     * password error the reseller cannot act on. The alphabet avoids characters that get
     * mangled when a password is pasted out of a page, read aloud, or typed on a phone.
     */
    public static function generatePassword(int $length = 16): string
    {
        $sets = ['abcdefghijkmnpqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '!@#%^*()-_=+'];
        $all = implode('', $sets);
        $password = [];

        foreach ($sets as $set) {
            $password[] = $set[random_int(0, strlen($set) - 1)];
        }

        while (count($password) < max(8, $length)) {
            $password[] = $all[random_int(0, strlen($all) - 1)];
        }

        // Shuffled so the guaranteed characters are not always in the same positions.
        for ($i = count($password) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$password[$i], $password[$j]] = [$password[$j], $password[$i]];
        }

        return implode('', $password);
    }

    // ------------------------------------------------------------- internals ---

    /**
     * The server and account to create the mailbox on.
     *
     * Deliberately the SAME settings the domain provisioner uses: the domain lives on that
     * account, and a mailbox for it must live where the domain does. The document root is
     * not consulted — see the class docblock.
     *
     * @return array{ready: bool, skipped: bool, message: string, server: array<string, mixed>, account: string}
     */
    private function target(): array
    {
        if (!$this->enabled()) {
            return [
                'ready' => false,
                'skipped' => true,
                'message' => 'Automatic provisioning is off — create the mailbox in cPanel by hand, then set it as your '
                    . 'support address (see docs/RESELLER_DOMAIN_SETUP.md).',
                'server' => [],
                'account' => '',
            ];
        }

        $serverId = (int) trim((string) $this->settings->get('reseller.cpanel_server_id', ''));
        $account = trim((string) $this->settings->get('reseller.cpanel_account_user', ''));

        if ($serverId <= 0 || $account === '') {
            return [
                'ready' => false,
                'skipped' => false,
                'message' => 'Provisioning is switched on but not configured: set the server and the cPanel account '
                    . 'under Reseller store provisioning settings.',
                'server' => [],
                'account' => '',
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
            ];
        }

        return ['ready' => true, 'skipped' => false, 'message' => '', 'server' => $server, 'account' => $account];
    }

    /** @return array{ok: false, skipped: false, message: string, address: null, password: null} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'skipped' => false, 'message' => $message, 'address' => null, 'password' => null];
    }
}
