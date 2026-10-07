<?php

declare(strict_types=1);

namespace CodeVault\UsernameChanger;

use CodeVault\Database;
use CodeVault\Security\PhpassHasher;
use CodeVault\Session\SessionManager;
use Throwable;

/**
 * Resets (or sets) a client's Security PIN from inside the username modal, so a
 * forgotten or locked PIN never sends the client away from the change they were
 * making. Two ways to prove it is them:
 *
 *  - an emailed 6-digit code (works for everyone, including clients who signed
 *    up with Google and never chose a password). The code lives in the session
 *    as a hash, expires after 10 minutes, allows 5 tries, and is sent at most 3
 *    times per 15 minutes. It is mailed in the client's own brand (the store's
 *    for a store customer) and never mirrored into in-app notifications, where
 *    anyone holding the session could read it;
 *  - the account password (bcrypt/Argon2 or a legacy phpass hash).
 *
 * A successful reset also clears the username-change PIN lockout, so the client
 * can submit again at once, and sends a "your PIN was changed" notice.
 */
final class UsernameChangePinReset
{
    public const CODE_TTL_SECONDS = 600;
    public const CODE_ATTEMPTS = 5;
    public const CODES_PER_WINDOW = 3;
    public const CODE_WINDOW_SECONDS = 900;
    public const RESETS_PER_HOUR = 10;
    public const PIN_MIN = 4;
    public const PIN_MAX = 12;

    private const SESSION_KEY = 'ucn_pin_code';

    public function __construct(
        private readonly Database $db,
        private readonly UsernameChangeRepository $requests,
        private readonly UsernameChangeNotifier $notifier,
        private readonly SessionManager $session,
        private readonly ?PhpassHasher $phpass = null
    ) {
    }

    /**
     * How this client can verify: always by emailed code; by password only when
     * the account has one stored.
     *
     * @param array<string, mixed> $client
     * @return array<int, string>
     */
    public function methods(array $client): array
    {
        return trim((string) ($client['password_hash'] ?? '')) === '' ? ['code'] : ['code', 'password'];
    }

    /**
     * @param array<string, mixed> $client
     * @return array{ok: bool, message: string, code?: string}
     */
    public function sendCode(array $client): array
    {
        $clientId = (int) $client['id'];
        $email = (string) ($client['email'] ?? '');

        if ($email === '') {
            return ['ok' => false, 'message' => 'Your account has no email address. Use your password instead.', 'code' => 'no_email'];
        }

        if (!$this->requests->hit('pincode:' . $clientId, self::CODES_PER_WINDOW, self::CODE_WINDOW_SECONDS)) {
            return ['ok' => false, 'message' => 'We have sent several codes already. Please check your inbox (and spam folder), or try again in a few minutes.', 'code' => 'code_throttled'];
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->session->set(self::SESSION_KEY, [
            'c' => $clientId,
            'h' => self::hashCode($clientId, $code),
            'exp' => time() + self::CODE_TTL_SECONDS,
            'n' => 0,
        ]);
        $this->notifier->pinCode($client, $code, intdiv(self::CODE_TTL_SECONDS, 60));

        return ['ok' => true, 'message' => 'We emailed a 6-digit code to ' . self::maskEmail($email) . '. It expires in ' . intdiv(self::CODE_TTL_SECONDS, 60) . ' minutes.'];
    }

    /**
     * @param array<string, mixed> $client from ClientAuthGuard (includes password_hash)
     * @param string $via    'code' | 'password'
     * @param string $secret the emailed code or the account password
     * @return array{ok: bool, message: string, code?: string}
     */
    public function reset(array $client, string $via, string $secret, string $pin, string $confirm): array
    {
        $clientId = (int) $client['id'];
        $pin = trim($pin);

        if (strlen($pin) < self::PIN_MIN || strlen($pin) > self::PIN_MAX) {
            return self::fail('Your new Security PIN must be ' . self::PIN_MIN . '–' . self::PIN_MAX . ' characters.', 'pin_length');
        }

        if (!hash_equals($pin, trim($confirm))) {
            return self::fail('The two PINs do not match.', 'pin_mismatch');
        }

        if (!$this->requests->hit('pinreset:' . $clientId, self::RESETS_PER_HOUR, 3600)) {
            return self::fail('Too many attempts. Please try again in an hour.', 'reset_throttled');
        }

        if ($via === 'password') {
            if (!in_array('password', $this->methods($client), true) || !$this->passwordMatches((string) ($client['password_hash'] ?? ''), $secret)) {
                return self::fail('That password is not correct.', 'password');
            }
        } elseif ($via === 'code') {
            $verdict = $this->checkCode($clientId, trim($secret));

            if ($verdict !== null) {
                return $verdict;
            }
        } else {
            return self::fail('Choose how to verify it is you.', 'via');
        }

        try {
            // Same column and algorithm as ClientRepository::updateSecurityPin(),
            // but falling back to PHP's default where Argon2 is not compiled in
            // (password_verify() reads either, so PIN checks are unaffected).
            $this->db->update(
                'UPDATE clients SET security_pin_hash = ?, updated_at = ? WHERE id = ?',
                [password_hash($pin, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT), date('Y-m-d H:i:s'), $clientId]
            );
        } catch (Throwable) {
            return self::fail('We could not save your new PIN. Please try again.', 'error');
        }

        $this->session->remove(self::SESSION_KEY);
        // The old lockout was about the old PIN; the client has just proved who
        // they are, so they may use the new one straight away.
        $this->requests->clearHits('pin:' . $clientId);
        $this->notifier->pinChanged($client);

        return ['ok' => true, 'message' => 'Your Security PIN has been updated.'];
    }

    /** @return array{ok: bool, message: string, code: string}|null null when the code is right */
    private function checkCode(int $clientId, string $code): ?array
    {
        $state = $this->session->get(self::SESSION_KEY);

        if (!is_array($state) || (int) ($state['c'] ?? 0) !== $clientId) {
            return self::fail('Request a code first.', 'code_missing');
        }

        if ((int) ($state['exp'] ?? 0) < time()) {
            $this->session->remove(self::SESSION_KEY);

            return self::fail('That code has expired. Request a new one.', 'code_expired');
        }

        if ($code !== '' && hash_equals((string) $state['h'], self::hashCode($clientId, $code))) {
            return null;
        }

        $state['n'] = (int) ($state['n'] ?? 0) + 1;

        if ($state['n'] >= self::CODE_ATTEMPTS) {
            $this->session->remove(self::SESSION_KEY);

            return self::fail('Too many wrong codes. Request a new one.', 'code_expired');
        }

        $this->session->set(self::SESSION_KEY, $state);

        return self::fail('That code is not correct.', 'code');
    }

    private function passwordMatches(string $hash, string $password): bool
    {
        if ($hash === '' || $password === '') {
            return false;
        }

        if (password_verify($password, $hash)) {
            return true;
        }

        return $this->phpass !== null && $this->phpass->isPhpassHash($hash) && $this->phpass->verify($password, $hash);
    }

    private static function hashCode(int $clientId, string $code): string
    {
        return hash('sha256', $clientId . '|' . $code);
    }

    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');

        if ($at === false || $at < 1) {
            return $email;
        }

        $local = substr($email, 0, $at);

        return substr($local, 0, 1) . str_repeat('•', max(2, min(6, strlen($local) - 1))) . substr($email, $at);
    }

    /** @return array{ok: bool, message: string, code: string} */
    private static function fail(string $message, string $code): array
    {
        return ['ok' => false, 'message' => $message, 'code' => $code];
    }
}
