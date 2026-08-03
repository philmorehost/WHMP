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

    public function __construct(
        private readonly ClientRepository $clients,
        private readonly ClientEmailValidationRepository $results,
        private readonly Database $db
    ) {
    }

    /** @return array{total: int, invalid: int} */
    public function scanAll(): array
    {
        $invalid = 0;
        $total = 0;

        foreach ($this->clients->activeForGroup(null) as $client) {
            $email = trim((string) $client['email']);

            if ($email === '') {
                continue;
            }

            $total++;
            $outcome = $this->checkOne($email);
            $this->results->upsert((int) $client['id'], $email, $outcome['valid'], $outcome['reason'], $outcome['recentFailures']);

            if (!$outcome['valid']) {
                $invalid++;
            }
        }

        return ['total' => $total, 'invalid' => $invalid];
    }

    /** @return array{valid: bool, reason: ?string, recentFailures: int} */
    private function checkOne(string $email): array
    {
        $atPos = strrpos($email, '@');
        $domain = $atPos !== false ? substr($email, $atPos + 1) : '';

        $recentFailures = $this->recentFailureCount($email);

        if ($domain === '' || (!checkdnsrr($domain, 'MX') && !checkdnsrr($domain, 'A') && !checkdnsrr($domain, 'AAAA'))) {
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

    private function recentFailureCount(string $email): int
    {
        $since = (new DateTimeImmutable('-' . self::FAILURE_LOOKBACK_DAYS . ' days'))->format('Y-m-d H:i:s');

        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS c FROM email_log WHERE to_email = ? AND status = 'failed' AND created_at >= ?",
            [$email, $since]
        );

        return (int) ($row['c'] ?? 0);
    }
}
