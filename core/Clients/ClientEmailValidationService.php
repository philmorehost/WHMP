<?php

declare(strict_types=1);

namespace CodeVault\Clients;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * Scans every client's email address for signs it can no longer receive
 * mail — built after a run of marketing sends came back as mail-daemon
 * bounce notices, which mail piping then turned into support tickets
 * (blueprint: proactively surface bad addresses instead of finding them
 * one bounce-ticket at a time).
 *
 * Two independent signals, checked for every client:
 *
 *  1. DNS: does the domain have anywhere to deliver to at all (an MX
 *     record, or an A/AAAA record as SMTP's own fallback when no MX
 *     exists — RFC 5321 §5.1)? A domain with neither can never receive
 *     mail, full stop — this catches typo'd or defunct domains.
 *
 *  2. Delivery history: has this exact address actually bounced recently,
 *     per email_log (real SendEmailJob outcomes, not a guess)? This is
 *     the one that catches the case DNS can't — a valid domain (gmail.com
 *     resolves fine) with a mailbox that doesn't exist or is full. This is
 *     usually the more direct signal for the bounce-ticket problem this
 *     tool exists for, since it's evidence of an actual failed attempt
 *     to this exact address, not an inference about the domain.
 *
 * Failures that are about OUR side (SMTP connection, TLS or login failing) are
 * not counted against the recipient, and a scan aborts without changing
 * anything if DNS itself is unreachable — both matter because the Invalid
 * Email Blocker addon stops mail to whatever this scan flags.
 *
 * Neither check sends a real email or otherwise contacts the recipient's
 * mail server (no SMTP RCPT probing) — DNS lookups are the only network
 * activity, so a scan cannot itself generate the kind of "someone is
 * probing my mail server" signal that a real deliverability checker would.
 */
final class ClientEmailValidationService
{
    /** How far back to count real bounces against an address. */
    private const FAILURE_LOOKBACK_DAYS = 90;

    /** Recent failures at or above this count flag the address even though DNS resolves fine. */
    private const FAILURE_THRESHOLD = 2;

    /**
     * Domains that always have mail servers. If none of them resolves, the
     * resolver itself is down, and every client would wrongly be flagged.
     */
    private const DNS_PROBES = ['gmail.com', 'outlook.com', 'yahoo.com'];

    /**
     * Failures that say nothing about the RECIPIENT: our own SMTP connection,
     * TLS or login failing, or a message we chose not to send. Counting these
     * would flag every client after one SMTP outage — and with the Invalid Email
     * Blocker on, stop their mail.
     */
    private const OUR_SIDE_FAILURE = '/could not connect|connection (refused|reset|timed out)|timed? ?out|starttls|tls negotiation|expected (220|334|235)|authentication|smtp is not configured|invalid from email|from address|not sent — invalid address/i';

    /** @var (callable(string, string): bool)|null test seam: fn (domain, type) => has record */
    private $resolver = null;

    public function __construct(
        private readonly ClientRepository $clients,
        private readonly ClientEmailValidationRepository $results,
        private readonly Database $db
    ) {
    }

    /**
     * A copy that answers DNS questions with $resolver instead of the network.
     *
     * @param callable(string, string): bool $resolver
     */
    public function withResolver(callable $resolver): self
    {
        $copy = clone $this;
        $copy->resolver = $resolver;

        return $copy;
    }

    /**
     * Scans every active client. When DNS itself is unreachable the scan stops
     * without changing anything and reports `aborted`.
     *
     * @return array{total: int, invalid: int, aborted: bool}
     */
    public function scanAll(): array
    {
        if (!$this->dnsWorks()) {
            return ['total' => 0, 'invalid' => 0, 'aborted' => true];
        }

        $invalid = 0;
        $total = 0;
        $domains = [];

        foreach ($this->clients->activeForGroup(null) as $client) {
            $email = trim((string) $client['email']);

            if ($email === '') {
                continue;
            }

            $total++;
            $outcome = $this->checkOne($email, $domains);
            $this->results->upsert((int) $client['id'], $email, $outcome['valid'], $outcome['reason'], $outcome['recentFailures']);

            if (!$outcome['valid']) {
                $invalid++;
            }
        }

        return ['total' => $total, 'invalid' => $invalid, 'aborted' => false];
    }

    /**
     * Checks ONE client's address right now — used when a client (or an admin)
     * changes it, so a fixed address stops being blocked and loses its banner
     * immediately instead of waiting for the next scan, and a new typo is
     * caught at once.
     *
     * Returns null — and changes nothing — when the check can't be trusted
     * (DNS unreachable) or doesn't apply (a reseller-store customer: the scan
     * and its report cover platform clients only). The stale row left behind
     * is harmless: it holds the OLD address, and both the blocker and the
     * banner match on the address.
     *
     * @return array{valid: bool, reason: ?string}|null
     */
    public function recheckClient(int $clientId, string $email, ?int $resellerId = null): ?array
    {
        $email = trim($email);

        if ($clientId <= 0 || $email === '' || $resellerId !== null) {
            return null;
        }

        $domains = [];
        $outcome = $this->checkOne($email, $domains);

        // "No mail server" might really be "no DNS": confirm the resolver works
        // before writing a verdict that would stop this client's mail.
        if (!$outcome['valid'] && $outcome['reason'] === 'No mail server found for this domain' && !$this->dnsWorks()) {
            return null;
        }

        $this->results->upsert($clientId, $email, $outcome['valid'], $outcome['reason'], $outcome['recentFailures']);

        return ['valid' => $outcome['valid'], 'reason' => $outcome['reason']];
    }

    /**
     * @param array<string, bool> $domains per-scan cache: one lookup per domain,
     *                                     however many clients share it
     * @return array{valid: bool, reason: ?string, recentFailures: int}
     */
    private function checkOne(string $email, array &$domains): array
    {
        $atPos = strrpos($email, '@');
        $domain = $atPos !== false ? strtolower(substr($email, $atPos + 1)) : '';

        $recentFailures = $this->recentFailureCount($email);

        if ($domain === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['valid' => false, 'reason' => 'Not a valid email address', 'recentFailures' => $recentFailures];
        }

        if (!array_key_exists($domain, $domains)) {
            $domains[$domain] = $this->canReceiveMail($domain);
        }

        if (!$domains[$domain]) {
            return ['valid' => false, 'reason' => 'No mail server found for this domain', 'recentFailures' => $recentFailures];
        }

        if ($recentFailures >= self::FAILURE_THRESHOLD) {
            return [
                'valid' => false,
                'reason' => "{$recentFailures} delivery failure(s) in the last " . self::FAILURE_LOOKBACK_DAYS . ' days',
                'recentFailures' => $recentFailures,
            ];
        }

        return ['valid' => true, 'reason' => null, 'recentFailures' => $recentFailures];
    }

    /** MX, or A/AAAA as SMTP's own fallback (RFC 5321 §5.1). Asked twice before giving up. */
    private function canReceiveMail(string $domain): bool
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($this->has($domain, 'MX') || $this->has($domain, 'A') || $this->has($domain, 'AAAA')) {
                return true;
            }
        }

        return false;
    }

    private function dnsWorks(): bool
    {
        foreach (self::DNS_PROBES as $probe) {
            if ($this->has($probe, 'MX')) {
                return true;
            }
        }

        return false;
    }

    private function has(string $domain, string $type): bool
    {
        if ($this->resolver !== null) {
            return (bool) ($this->resolver)($domain, $type);
        }

        return checkdnsrr($domain . '.', $type);
    }

    private function recentFailureCount(string $email): int
    {
        $since = (new DateTimeImmutable('-' . self::FAILURE_LOOKBACK_DAYS . ' days'))->format('Y-m-d H:i:s');

        $rows = $this->db->select(
            "SELECT error FROM email_log WHERE to_email = ? AND status = 'failed' AND created_at >= ?",
            [$email, $since]
        );

        $count = 0;

        foreach ($rows as $row) {
            if (preg_match(self::OUR_SIDE_FAILURE, (string) ($row['error'] ?? '')) !== 1) {
                $count++;
            }
        }

        return $count;
    }
}
