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

    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * Issues a fresh code for this email, invalidating any earlier one —
     * only the most recently sent code is ever valid, so a resend can't
     * leave two codes both accepted at once.
     */
    public function issue(string $email): string
    {
        $email = strtolower(trim($email));
        $code = str_pad((string) random_int(0, 10 ** self::CODE_LENGTH - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);
        $now = new DateTimeImmutable();

        $this->db->delete('DELETE FROM client_registration_otps WHERE email = ?', [$email]);

        $this->db->insert(
            'INSERT INTO client_registration_otps (email, code_hash, attempts, expires_at, created_at) VALUES (?, ?, 0, ?, ?)',
            [
                $email,
                password_hash($code, PASSWORD_DEFAULT),
                $now->modify('+' . self::EXPIRY_MINUTES . ' minutes')->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ]
        );

        return $code;
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
