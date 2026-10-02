<?php

declare(strict_types=1);

namespace CodeVault\Reseller;

/**
 * Can a store's domain actually send as itself?
 *
 * This is the difference between the white-label feature working and quietly losing a
 * reseller's support replies. Receiving providers check DMARC alignment: a message
 * whose From: domain did not authorise the sending host is unauthenticated, and
 * `p=reject` policies refuse it outright. Nothing in this application would notice —
 * the send succeeds locally and the customer simply never sees the mail.
 *
 * ALIGNED means EITHER mechanism holds, because either is sufficient:
 *
 *   SPF   the From domain's TXT record authorises us. Recognised in three forms, and
 *         the third is the one people miss: `include:<platform>` (what we tell
 *         resellers to add), and a bare `a` or `mx` token — both of which authorise the
 *         domains's OWN records, and on our panel the domain's A record and MX both
 *         point at the server we send from. So a reseller hosted here is frequently
 *         already aligned without knowing it.
 *
 *   DKIM  a key exists at `<selector>._domainkey.<domain>`. On a cPanel host this is
 *         the stronger path: Exim signs outgoing mail with the domain's own key, so a
 *         published key aligns the signature with the From domain even when SPF does
 *         not mention us.
 *
 * UNAVAILABLE IS NOT MISALIGNED, and the distinction is the point. A resolver outage, a
 * provider that rate-limits, or an unconfigured platform must not be reported as "your
 * domain is broken" — that sends a reseller editing DNS that was already correct. We say
 * we could not tell, and the portal shows that as a state of its own.
 *
 * The DNS lookup is injected, so no test here touches the network, and so a caller can
 * supply a cached resolver.
 */
final class MailDomainAlignment
{
    public const ALIGNED = 'aligned';
    public const MISALIGNED = 'misaligned';
    public const UNAVAILABLE = 'unavailable';

    /** @var callable(string): ?array<int, string> */
    private $txtLookup;

    /**
     * @param callable(string): ?array<int, string> $txtLookup
     *        Returns the domain's TXT records, or NULL when the lookup could not be
     *        performed at all. NULL and [] mean different things and must not be
     *        collapsed: [] is "there are no records", NULL is "we do not know".
     * @param string $spfInclude the host we tell resellers to authorise, e.g. spf.example.com
     * @param string $dkimSelector the selector the panel signs with (cPanel's default is 'default')
     */
    public function __construct(
        callable $txtLookup,
        private readonly string $spfInclude,
        private readonly string $dkimSelector = 'default'
    ) {
        $this->txtLookup = $txtLookup;
    }

    /**
     * A checker wired to the system resolver — what production uses.
     *
     * The constructor takes the lookup as a callable precisely so tests never touch the
     * network; this is the one place that supplies the real one. A failed lookup returns
     * NULL, which is what lets the checker say "we could not tell" instead of inventing a
     * failure. `dns_get_record` returns false on error and an empty array on "no such
     * records", and collapsing those two is exactly the mistake the class is written to
     * avoid, so they are kept apart here.
     */
    public static function withDns(string $spfInclude, string $dkimSelector = 'default'): self
    {
        return new self(
            static function (string $name): ?array {
                $records = @dns_get_record($name, DNS_TXT);

                if ($records === false) {
                    return null;
                }

                $values = [];

                foreach ($records as $record) {
                    // The key is 'txt' on most builds and 'entries' on some; both have been
                    // seen in the wild for the same query.
                    if (isset($record['txt']) && is_string($record['txt'])) {
                        $values[] = $record['txt'];
                    } elseif (isset($record['entries']) && is_array($record['entries'])) {
                        $values[] = implode('', array_map('strval', $record['entries']));
                    }
                }

                return $values;
            },
            $spfInclude,
            $dkimSelector
        );
    }

    /**
     * @return array{status: string, detail: ?string, spf: ?string, dkim: bool}
     */
    public function check(string $fromAddress): array
    {
        $at = strrchr($fromAddress, '@');

        if ($at === false) {
            return [
                'status' => self::MISALIGNED,
                'detail' => 'That is not an email address, so we cannot check a domain for it.',
                'spf' => null,
                'dkim' => false,
            ];
        }

        $domain = strtolower(trim(substr($at, 1)));

        if ($domain === '') {
            return [
                'status' => self::MISALIGNED,
                'detail' => 'That address has no domain, so there is nothing to check.',
                'spf' => null,
                'dkim' => false,
            ];
        }

        $spfRecords = $this->lookup($domain);
        $dkimRecords = $this->lookup($this->dkimSelector . '._domainkey.' . $domain);

        // Neither lookup could run: we have no evidence either way, and saying
        // "misaligned" here would be inventing a failure.
        if ($spfRecords === null && $dkimRecords === null) {
            return [
                'status' => self::UNAVAILABLE,
                'detail' => 'We could not reach DNS to check ' . $domain
                    . '. Nothing is wrong with your address — it simply has not been confirmed yet.',
                'spf' => null,
                'dkim' => false,
            ];
        }

        $spfRecord = $this->spfRecord($spfRecords ?? []);
        $spfAuthorises = $spfRecord !== null && $this->spfAuthorises($spfRecord);
        $dkimPresent = $this->dkimPresent($dkimRecords ?? []);

        if ($spfAuthorises || $dkimPresent) {
            return [
                'status' => self::ALIGNED,
                'detail' => $dkimPresent
                    ? 'DKIM is published for ' . $domain . ', so mail sent as this address is signed for your domain.'
                    : 'Your SPF record authorises our server to send for ' . $domain . '.',
                'spf' => $spfRecord,
                'dkim' => $dkimPresent,
            ];
        }

        return [
            'status' => self::MISALIGNED,
            'detail' => $this->remediation($domain, $spfRecord),
            'spf' => $spfRecord,
            'dkim' => false,
        ];
    }

    /**
     * Exactly what to publish.
     *
     * A warning with no fix leaves the reseller with a problem they cannot act on, which
     * is why the records are spelled out rather than described.
     */
    private function remediation(string $domain, ?string $spfRecord): string
    {
        $host = trim($this->spfInclude);
        $lines = [];

        if ($spfRecord === null) {
            $lines[] = 'Add a TXT record on ' . $domain . ' with the value: v=spf1 include:' . $host . ' ~all';
        } else {
            $lines[] = 'Your existing SPF record (' . $spfRecord . ') does not authorise our server. Add '
                . 'include:' . $host . ' to it, before the final "all" mechanism.';
        }

        $lines[] = 'Or publish the DKIM key for ' . $domain
            . ', which is signed automatically once it exists.';

        return implode(' ', $lines);
    }

    /** @return array<int, string>|null */
    private function lookup(string $name): ?array
    {
        $lookup = $this->txtLookup;

        return $lookup($name);
    }

    /**
     * @param array<int, string> $records
     */
    private function spfRecord(array $records): ?string
    {
        foreach ($records as $record) {
            $trimmed = trim($record);

            if (stripos($trimmed, 'v=spf1') === 0) {
                return $trimmed;
            }
        }

        return null;
    }

    /**
     * Does this SPF record authorise the host we send from?
     *
     * Deliberately narrow. `~all` and `-all` are qualifiers on the DEFAULT outcome, not
     * authorisations, so their presence proves nothing. Only three things count:
     * our include, or `a`/`mx`, which authorise the domain's own A/MX records — and on a
     * panel-hosted domain those are us.
     */
    private function spfAuthorises(string $record): bool
    {
        $include = trim($this->spfInclude);

        if ($include !== '' && stripos($record, 'include:' . $include) !== false) {
            return true;
        }

        foreach (preg_split('/\s+/', $record) ?: [] as $mechanism) {
            $token = strtolower(trim($mechanism, " \t()"));

            if ($token === 'a' || $token === 'mx' || str_starts_with($token, 'a:') || str_starts_with($token, 'mx:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is there a usable DKIM key?
     *
     * A TXT record at the selector name that carries a `p=` tag — an empty `p=` is a
     * REVOKED key, which is a deliberate signal meaning "do not trust signatures from
     * this selector". Treating its presence as aligned would be exactly backwards.
     *
     * @param array<int, string> $records
     */
    private function dkimPresent(array $records): bool
    {
        foreach ($records as $record) {
            if (preg_match('/\bp=([A-Za-z0-9+\/=\s]+)/', trim($record), $matches) === 1
                && trim($matches[1]) !== '') {
                return true;
            }
        }

        return false;
    }
}
