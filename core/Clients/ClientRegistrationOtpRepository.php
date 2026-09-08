<?php

declare(strict_types=1);

namespace CodeVault\Clients;

use CodeVault\Database;
use DateTimeImmutable;

/**
 * One-time codes for the "verify your email" step of plain email/password
 * registration (blueprint: OTP required at signup, except for Google
 * sign-up, which already proves the email belongs to the person signing
 * up). Codes are hashed at rest, same as a password — a 6-digit code has
 * low entropy on its own, so the real protection is the attempt cap here
 * plus BruteGuard-style short expiry, not secrecy of the hash.
 */
final class ClientRegistrationOtpRepository
{
    private const CODE_LENGTH = 6;
    public const EXPIRY_MINUTES = 15;
    private const MAX_ATTEMPTS = 5;

    // Abuse throttling for the public OTP-issue endpoints (registration and
    // resend are reachable with no login at all). Without these, a scripted
    // caller could drive the app's mail transport to send one OTP email per
    // request to arbitrary addresses — the "use the billing portal as a bulk
    // mailer" vector. issue() keeps no per-email history (each issue deletes
    // the prior row for that address), so the cooldown reads the current
    // row's created_at and the per-IP cap counts rows left behind by OTHER
    // addresses, which accumulate across the window.
    private const RESEND_COOLDOWN_SECONDS = 60;
    private const MAX_ISSUES_PER_IP = 5;
    private const IP_WINDOW_MINUTES = 60;

    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * Issues a fresh code for this email, invalidating any earlier one —
     * only the most recently sent code is ever valid, so a resend can't
     * leave two codes both accepted at once. The requester's IP is stored so
     * the per-IP issue cap in tooManyIssuesFromIp() can be enforced.
     */
    public function issue(string $email, string $ip = ''): string
    {
        $email = strtolower(trim($email));
        $code = str_pad((string) random_int(0, 10 ** self::CODE_LENGTH - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);
        $now = new DateTimeImmutable();

        $this->db->delete('DELETE FROM client_registration_otps WHERE email = ?', [$email]);

        $this->db->insert(
            'INSERT INTO client_registration_otps (email, code_hash, attempts, ip_address, expires_at, created_at) VALUES (?, ?, 0, ?, ?, ?)',
            [
                $email,
                password_hash($code, PASSWORD_DEFAULT),
                $ip !== '' ? substr(trim($ip), 0, 45) : null,
                $now->modify('+' . self::EXPIRY_MINUTES . ' minutes')->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ]
        );

        return $code;
    }

    /**
     * Seconds still to wait before another code may be issued for this same
     * address (0 = allowed now). Guards the resend button and repeat
     * registration attempts against a specific inbox.
     */
    public function cooldownRemaining(string $email): int
    {
        $email = strtolower(trim($email));
        $row = $this->db->selectOne(
            'SELECT created_at FROM client_registration_otps WHERE email = ? ORDER BY created_at DESC LIMIT 1',
            [$email]
        );

        if ($row === null || $row['created_at'] === null) {
            return 0;
        }

        $elapsed = time() - (int) strtotime((string) $row['created_at']);

        return max(0, self::RESEND_COOLDOWN_SECONDS - $elapsed);
    }

    /**
     * True when this IP has already issued its allowance of codes in the
     * current window — stops a single caller fanning OTP emails out to many
     * different addresses. Rows for different addresses accumulate (issue()
     * only deletes the row for the address it is replacing).
     */
    public function tooManyIssuesFromIp(string $ip, int $max = self::MAX_ISSUES_PER_IP): bool
    {
        $ip = trim($ip);

        if ($ip === '') {
            return false;
        }

        $since = (new DateTimeImmutable())
            ->modify('-' . self::IP_WINDOW_MINUTES . ' minutes')
            ->format('Y-m-d H:i:s');

        $count = (int) ($this->db->selectOne(
            'SELECT COUNT(*) AS c FROM client_registration_otps WHERE ip_address = ? AND created_at >= ?',
            [$ip, $since]
        )['c'] ?? 0);

        return $count >= $max;
    }

    /**
     * Checks a submitted code. Every call — right or wrong — counts against
     * the attempt cap, and a code that has hit the cap or expired is treated
     * as gone (the caller has to request a new one via issue()) rather than
     * left around to keep absorbing guesses.
     */
    public function verify(string $email, string $code): bool
    {
        $email = strtolower(trim($email));
        $row = $this->db->selectOne('SELECT * FROM client_registration_otps WHERE email = ?', [$email]);

        if ($row === null) {
            return false;
        }

        if ((int) $row['attempts'] >= self::MAX_ATTEMPTS || strtotime((string) $row['expires_at']) < time()) {
            $this->db->delete('DELETE FROM client_registration_otps WHERE id = ?', [$row['id']]);

            return false;
        }

        $this->db->update('UPDATE client_registration_otps SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);

        if (!password_verify($code, (string) $row['code_hash'])) {
            return false;
        }

        $this->db->delete('DELETE FROM client_registration_otps WHERE id = ?', [$row['id']]);

        return true;
    }

    /** Whether a still-valid (unexpired, under the attempt cap) code exists for this email. */
    public function hasPending(string $email): bool
    {
        $email = strtolower(trim($email));
        $row = $this->db->selectOne(
            'SELECT id FROM client_registration_otps WHERE email = ? AND expires_at >= ? AND attempts < ?',
            [$email, (new DateTimeImmutable())->format('Y-m-d H:i:s'), self::MAX_ATTEMPTS]
        );

        return $row !== null;
    }

    public function invalidate(string $email): void
    {
        $this->db->delete('DELETE FROM client_registration_otps WHERE email = ?', [strtolower(trim($email))]);
    }
}
