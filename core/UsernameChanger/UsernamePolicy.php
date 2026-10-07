<?php

declare(strict_types=1);

namespace CodeVault\UsernameChanger;

/**
 * The pure username rules (plan §5, rules 1–5 and 7). No database, no network:
 * the same rules are mirrored in the modal's JavaScript so the client sees a
 * verdict on every keystroke with zero round-trips, and run again here on the
 * server, which is the one that counts.
 */
final class UsernamePolicy
{
    /** cPanel's own ceiling when database prefixing is on. */
    public const HARD_MAX = 16;
    public const HARD_MIN = 1;

    /** System and service names cPanel/WHM, Linux or a hosting stack already use. */
    public const RESERVED = [
        'root', 'admin', 'administrator', 'cpanel', 'whm', 'webmail', 'mysql', 'postgres', 'postgresql',
        'nobody', 'mail', 'mailman', 'ftp', 'www', 'wwwdata', 'apache', 'nginx', 'httpd', 'named', 'bind',
        'dovecot', 'exim', 'virtfs', 'cpses', 'all', 'support', 'billing', 'daemon', 'bin', 'sys', 'sync',
        'games', 'man', 'lp', 'news', 'uucp', 'proxy', 'backup', 'list', 'irc', 'gnats', 'operator',
        'shutdown', 'halt', 'cpanelphpmyadmin', 'cpanelphppgadmin', 'cpanelroundcube', 'cpanellogin',
        'cpanelconnecttrack', 'cpanelanalytics', 'cpanelcabcache', 'cpaneleximfilter', 'cpaneleximscanner',
        'cpanelsolr', 'mailnull', 'smmsp', 'clamav', 'spamassassin', 'redis', 'memcached', 'git', 'svn',
        'cpanelguest', 'tomcat', 'postfix', 'sshd', 'systemd', 'dbus', 'polkitd', 'chrony', 'ntp', 'abrt',
        'tss', 'rpc', 'rpcuser', 'nfsnobody', 'mailer', 'postmaster', 'hostmaster', 'webmaster', 'abuse',
        'security', 'noc', 'info', 'sales', 'help', 'host', 'server', 'localhost', 'reseller', 'cloud',
        'dns', 'ns', 'ns1', 'ns2', 'smtp', 'pop', 'pop3', 'imap', 'whois', 'autoconfig', 'autodiscover',
        'cpcalendars', 'cpcontacts', 'webdisk', 'cpanelemail', 'whmcs', 'whmp', 'codevault',
    ];

    /** @param array<int, string> $extraReserved */
    public function __construct(
        private readonly int $minLength = 5,
        private readonly int $maxLength = 16,
        private readonly array $extraReserved = []
    ) {
    }

    public function minLength(): int
    {
        return max(self::HARD_MIN, min($this->minLength, $this->maxLength()));
    }

    public function maxLength(): int
    {
        return max(self::HARD_MIN, min(self::HARD_MAX, $this->maxLength));
    }

    /** @return array<int, string> every reserved word, lower-case, de-duplicated */
    public function reservedWords(): array
    {
        $words = self::RESERVED;

        foreach ($this->extraReserved as $word) {
            $word = strtolower(trim((string) $word));

            if ($word !== '') {
                $words[] = $word;
            }
        }

        return array_values(array_unique($words));
    }

    public static function normalise(string $value): string
    {
        return strtolower(trim($value));
    }

    /**
     * The first rule this name breaks, as [code, plain-English message], or
     * null when it passes every local rule.
     *
     * @return array{0: string, 1: string}|null
     */
    public function violation(string $username, ?string $current = null): ?array
    {
        $min = $this->minLength();
        $max = $this->maxLength();

        if ($username === '') {
            return ['empty', 'Enter a username.'];
        }

        if (preg_match('/[A-Z]/', $username)) {
            return ['case', 'Use lowercase letters only.'];
        }

        if (preg_match('/^[0-9]/', $username)) {
            return ['leading_digit', 'The username must start with a letter.'];
        }

        if (!preg_match('/^[a-z][a-z0-9]*$/', $username)) {
            return ['chars', 'Use only lowercase letters (a–z) and digits (0–9).'];
        }

        if (strlen($username) < $min) {
            return ['short', "Use at least {$min} characters."];
        }

        if (strlen($username) > $max) {
            return ['long', "Use at most {$max} characters."];
        }

        if (str_starts_with($username, 'test')) {
            return ['test_prefix', 'cPanel does not allow usernames that start with "test".'];
        }

        if (in_array($username, $this->reservedWords(), true)) {
            return ['reserved', 'That name is reserved by the system. Please choose another.'];
        }

        if ($current !== null && $username === strtolower($current)) {
            return ['same', 'That is already your username.'];
        }

        return null;
    }

    /**
     * Candidate names (not yet checked for availability) built from the
     * requested name, the domain and the client's name. Every candidate passes
     * the local rules.
     *
     * @param array<string, mixed> $context  domain, first_name, last_name, current
     * @return array<int, string>
     */
    public function suggestions(string $requested, array $context = [], int $limit = 12): array
    {
        $max = $this->maxLength();
        $stems = [];

        $clean = static fn (string $s): string => preg_replace('/[^a-z0-9]/', '', strtolower($s)) ?? '';
        $requested = $clean($requested);
        $requested = ltrim($requested, '0123456789');

        if ($requested !== '') {
            $stems[] = $requested;
        }

        $domain = strtolower((string) ($context['domain'] ?? ''));

        if ($domain !== '') {
            $label = $clean(explode('.', $domain)[0] ?? '');
            $label = ltrim($label, '0123456789');

            if ($label !== '') {
                $stems[] = $label;
                $stems[] = preg_replace('/[aeiou]/', '', substr($label, 0, 1)) . preg_replace('/[aeiou]/', '', substr($label, 1));
            }
        }

        $first = ltrim($clean((string) ($context['first_name'] ?? '')), '0123456789');
        $last = $clean((string) ($context['last_name'] ?? ''));

        if ($first !== '') {
            $stems[] = $first . $last;
            if ($last !== '') {
                $stems[] = substr($first, 0, 1) . $last;
            }
        }

        $out = [];
        $current = isset($context['current']) ? (string) $context['current'] : null;
        $add = function (string $candidate) use (&$out, $current): void {
            if (!in_array($candidate, $out, true) && $this->violation($candidate, $current) === null) {
                $out[] = $candidate;
            }
        };

        foreach ($stems as $stem) {
            $stem = (string) $stem;
            if ($stem === '') {
                continue;
            }
            if ($stem !== $requested) {
                $add(substr($stem, 0, $max));
            }
            $add(substr($stem, 0, min($max, 8)));
        }

        // Deterministic digit suffixes so the same input gives the same chips
        // (and the browser's cache stays useful).
        $seed = abs(crc32($requested . '|' . $domain));

        foreach ($stems as $i => $stem) {
            $stem = (string) $stem;
            if ($stem === '') {
                continue;
            }
            foreach ([2, 3] as $digits) {
                $suffix = (string) (($seed >> ($i + $digits)) % (10 ** $digits));
                $suffix = str_pad($suffix, $digits, '0', STR_PAD_LEFT);
                $add(substr($stem, 0, $max - strlen($suffix)) . $suffix);
            }
        }

        return array_slice($out, 0, $limit);
    }

    /** Rule set handed to the browser so the modal can check instantly. */
    public function toClientRules(): array
    {
        return [
            'min' => $this->minLength(),
            'max' => $this->maxLength(),
            'reserved' => $this->reservedWords(),
        ];
    }
}
