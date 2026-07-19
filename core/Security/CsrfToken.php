<?php

declare(strict_types=1);

namespace CodeVault\Security;

use CodeVault\Session\SessionManager;

/**
 * Session-backed CSRF token (blueprint R11 hardening pass, alongside
 * SecurityHeaders and SameSite=Lax cookies in SessionManager). One token
 * per session, generated on first access and compared with hash_equals()
 * against the `_token` field a form submits — a synchronizer-token
 * implementation of the double-submit pattern the session cookie already
 * being HttpOnly/SameSite=Lax rules out doing via a second readable
 * cookie. Belt-and-suspenders on top of SameSite=Lax: covers older
 * browsers and edge cases (subdomain takeover, etc.) SameSite alone
 * doesn't.
 */
final class CsrfToken
{
    private const SESSION_KEY = '_csrf_token';

    public function __construct(
        private readonly SessionManager $session
    ) {
    }

    /**
     * Returns the current session's token, generating one on first call.
     */
    public function get(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (!is_string($token) || $token === '') {
            $token = $this->generate();
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /**
     * Forces a fresh token, discarding any existing one — for callers
     * that want to rotate on privilege changes.
     */
    public function rotate(): string
    {
        $token = $this->generate();
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    public function verify(mixed $submitted): bool
    {
        if (!is_string($submitted) || $submitted === '') {
            return false;
        }

        $token = $this->session->get(self::SESSION_KEY);

        return is_string($token) && $token !== '' && hash_equals($token, $submitted);
    }

    private function generate(): string
    {
        return bin2hex(random_bytes(32));
    }
}
