<?php

declare(strict_types=1);

namespace CodeVault\Mail;

use CodeVault\Database;
use CodeVault\Modules\AddonModuleRepository;
use DateTimeImmutable;
use Throwable;

/**
 * Invalid Email Blocker: decides whether an outgoing email must be skipped
 * because its address is known to be undeliverable.
 *
 * "Known to be undeliverable" means exactly one thing: the Email Validation
 * scan (Admin → Clients → Email Validation, ClientEmailValidationService)
 * marked that address Invalid — no mail server for its domain, not a valid
 * address at all, or repeated real delivery failures. Nothing is guessed here.
 *
 * Settings live in the addon's own config (AddonModuleRepository), so the
 * whole feature is OFF until an admin activates the "Invalid Email Blocker"
 * addon, and its ON/OFF switch can be flipped any time after that:
 *
 *   block           bool  skip emails to invalid addresses (default ON once activated)
 *   allow_security  bool  still send account-security emails (password reset,
 *                         sign-up codes, PIN codes) to them — default ON, so a
 *                         false positive can never lock a client out
 *   rescan_days     int   re-run the scan automatically every N days (0 = never)
 *   banner          bool  show flagged clients a banner in the client area asking
 *                         them to update their email (default ON) — see clientNotice()
 *   allow           list  addresses that must always receive email, whatever the
 *                         scan says (lower-cased)
 *
 * EmailDispatcher asks this before every send — that is the single path every
 * client email takes (invoices, tickets, reminders, campaigns, everything), so
 * one check covers all email types. A skipped email is still logged (status
 * 'suppressed', with the reason) and still mirrored into the client's in-app
 * notifications, so nothing is silently lost.
 *
 * FAILS OPEN: any error reading the settings or the scan results means "send".
 * A blocker that blinked would otherwise stop invoices and password resets.
 */
final class EmailSuppression
{
    public const SLUG = 'invalid-email-blocker';

    /** Account-security mail: still sent to a flagged address while allow_security is on. */
    public const SECURITY_TEMPLATES = [
        'client_password_reset',
        'admin_password_reset',
        'client_registration_otp',
        'username_change.pin_code',
        'username_change.pin_changed',
    ];

    /** How long settings and lookups are trusted inside one long-running process. */
    private const CACHE_SECONDS = 60;

    /** @var array{active: bool, block: bool, allow_security: bool, rescan_days: int, banner: bool, allow: array<int, string>}|null */
    private ?array $state = null;

    /** @var array<string, ?string> lower-cased address => reason it is blocked (null = not blocked) */
    private array $memo = [];

    private int $loadedAt = 0;

    public function __construct(
        private readonly AddonModuleRepository $addons,
        private readonly Database $db
    ) {
    }

    /**
     * The reason this email must NOT be sent, or null to send it.
     */
    public function reasonFor(string $email, ?string $templateKey = null): ?string
    {
        try {
            $state = $this->state();

            if (!$state['active'] || !$state['block']) {
                return null;
            }

            $key = self::normalizeEmail($email);

            if ($key === '' || in_array($key, $state['allow'], true)) {
                return null;
            }

            if ($state['allow_security'] && $templateKey !== null && in_array($templateKey, self::SECURITY_TEMPLATES, true)) {
                return null;
            }

            if (!array_key_exists($key, $this->memo)) {
                $row = $this->db->selectOne(
                    'SELECT reason FROM client_email_validations WHERE LOWER(email) = ? AND is_valid = 0 LIMIT 1',
                    [$key]
                );
                $this->memo[$key] = $row === null ? null : (trim((string) ($row['reason'] ?? '')) ?: 'Marked invalid by Email Validation');
            }

            return $this->memo[$key];
        } catch (Throwable) {
            return null;
        }
    }

    /** True when the addon is active AND its switch is ON. */
    public function isBlocking(): bool
    {
        try {
            $state = $this->state();

            return $state['active'] && $state['block'];
        } catch (Throwable) {
            return false;
        }
    }

    /** True when the addon is activated (whatever its switch says). */
    public function isActive(): bool
    {
        try {
            return $this->state()['active'];
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{active: bool, block: bool, allow_security: bool, rescan_days: int, banner: bool, allow: array<int, string>} */
    public function settings(): array
    {
        return $this->state();
    }

    /**
     * Turns blocking ON or OFF. Turning it ON also activates the addon, so the
     * switch on the Email Validation page works without a detour to Addons.
     */
    public function setBlocking(bool $on): void
    {
        $config = $this->config();
        $config['block'] = $on;
        $this->addons->setConfig(self::SLUG, $config);

        if ($on && !$this->addons->isActive(self::SLUG)) {
            $this->addons->activate(self::SLUG);
        }

        $this->forget();
    }

    /** @param array<string, mixed> $input raw form input */
    public function save(array $input): void
    {
        $config = self::normalize($input + ['block' => false, 'allow_security' => false, 'banner' => false]);
        unset($config['active']);
        $this->addons->setConfig(self::SLUG, $config);
        $this->forget();
    }

    /** Always deliver to this address, whatever the scan says. */
    public function allow(string $email): void
    {
        $key = self::normalizeEmail($email);

        if ($key === '') {
            return;
        }

        $config = $this->config();
        $allow = self::normalizeList($config['allow'] ?? []);

        if (!in_array($key, $allow, true)) {
            $allow[] = $key;
        }

        $config['allow'] = $allow;
        $this->addons->setConfig(self::SLUG, $config);
        $this->forget();
    }

    /** Back to normal: blocked again if the scan marks it invalid. */
    public function disallow(string $email): void
    {
        $key = self::normalizeEmail($email);
        $config = $this->config();
        $config['allow'] = array_values(array_filter(self::normalizeList($config['allow'] ?? []), static fn (string $a): bool => $a !== $key));
        $this->addons->setConfig(self::SLUG, $config);
        $this->forget();
    }

    /**
     * What the signed-in client should be told about their own address, or null
     * for nothing: the addon is active with its banner setting on, the client's
     * CURRENT email is the one the scan marked invalid (a client who has since
     * changed it is not nagged about the old one), and it is not on the
     * always-send list. Platform clients only — store customers are never
     * scanned, and nothing platform-side appears on a reseller's store.
     *
     * Fails quiet: any error means no banner.
     *
     * @return array{email: string, reason: string, blocking: bool}|null
     */
    public function clientNotice(int $clientId): ?array
    {
        if ($clientId <= 0) {
            return null;
        }

        try {
            $state = $this->state();

            if (!$state['active'] || !$state['banner']) {
                return null;
            }

            $row = $this->db->selectOne(
                <<<'SQL'
                SELECT c.email, v.reason
                FROM clients c
                JOIN client_email_validations v ON v.client_id = c.id
                WHERE c.id = ? AND c.reseller_id IS NULL AND v.is_valid = 0 AND LOWER(v.email) = LOWER(c.email)
                LIMIT 1
                SQL,
                [$clientId]
            );

            if ($row === null || in_array(self::normalizeEmail((string) $row['email']), $state['allow'], true)) {
                return null;
            }

            return [
                'email' => (string) $row['email'],
                'reason' => self::friendlyReason((string) ($row['reason'] ?? '')),
                'blocking' => $state['block'],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** The scan's reason, worded for the client rather than the admin. */
    public static function friendlyReason(string $reason): string
    {
        return match (true) {
            str_starts_with($reason, 'Not a valid') => "it isn't a complete email address",
            str_starts_with($reason, 'No mail server') => "its domain can't receive email (it may have expired or have a typo)",
            str_contains($reason, 'delivery failure') => 'our recent emails to it were rejected',
            default => "our emails to it can't be delivered",
        };
    }

    /**
     * @return array{blocked: int, skipped30: int, allowed: int}
     */
    public function stats(): array
    {
        $state = $this->state();
        $blocked = 0;

        foreach ($this->db->select('SELECT DISTINCT LOWER(email) AS email FROM client_email_validations WHERE is_valid = 0') as $row) {
            if (!in_array((string) $row['email'], $state['allow'], true)) {
                $blocked++;
            }
        }

        $since = (new DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s');
        $skipped = $this->db->selectOne("SELECT COUNT(*) AS c FROM email_log WHERE status = 'suppressed' AND created_at >= ?", [$since]);

        return ['blocked' => $blocked, 'skipped30' => (int) ($skipped['c'] ?? 0), 'allowed' => count($state['allow'])];
    }

    /** @return array<int, array<string, mixed>> the most recent skipped emails */
    public function recentSkipped(int $limit = 15): array
    {
        $limit = max(1, min(100, $limit));

        return $this->db->select(
            "SELECT id, to_email, subject, template_key, client_id, error, created_at FROM email_log WHERE status = 'suppressed' ORDER BY id DESC LIMIT {$limit}"
        );
    }

    /**
     * Settings with defaults applied. A missing `block` means ON: activating
     * the addon is the admin saying "start blocking".
     *
     * @param array<string, mixed> $config
     * @return array{block: bool, allow_security: bool, rescan_days: int, banner: bool, allow: array<int, string>}
     */
    public static function normalize(array $config): array
    {
        $allow = $config['allow'] ?? [];

        if (is_string($allow)) {
            $allow = preg_split('/[\s,;]+/', $allow) ?: [];
        }

        return [
            'block' => self::bool($config['block'] ?? true),
            'allow_security' => self::bool($config['allow_security'] ?? true),
            'rescan_days' => max(0, min(90, (int) ($config['rescan_days'] ?? 7))),
            'banner' => self::bool($config['banner'] ?? true),
            'allow' => self::normalizeList((array) $allow),
        ];
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * @param array<int|string, mixed> $list
     * @return array<int, string>
     */
    private static function normalizeList(array $list): array
    {
        $out = [];

        foreach ($list as $email) {
            $email = self::normalizeEmail((string) $email);

            if ($email !== '' && str_contains($email, '@') && !in_array($email, $out, true)) {
                $out[] = $email;
            }
        }

        return array_slice($out, 0, 500);
    }

    private static function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true);
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return $this->addons->getConfig(self::SLUG);
    }

    /** @return array{active: bool, block: bool, allow_security: bool, rescan_days: int, banner: bool, allow: array<int, string>} */
    private function state(): array
    {
        if ($this->state === null || time() - $this->loadedAt > self::CACHE_SECONDS) {
            $this->state = ['active' => $this->addons->isActive(self::SLUG)] + self::normalize($this->config());
            $this->memo = [];
            $this->loadedAt = time();
        }

        return $this->state;
    }

    private function forget(): void
    {
        $this->state = null;
        $this->memo = [];
    }
}
