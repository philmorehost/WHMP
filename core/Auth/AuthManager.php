<?php

declare(strict_types=1);

namespace CodeVault\Auth;

use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Security\AccountLockRepository;
use CodeVault\Security\BruteGuard;

/**
 * Wires BruteGuard's pre-check and failure/success recording around
 * `password_verify()` — the one place admin login logic lives.
 */
final class AuthManager
{
    public function __construct(
        private readonly AdminRepository $admins,
        private readonly BruteGuard $bruteGuard,
        private readonly AccountLockRepository $accountLocks,
        private readonly HookDispatcher $hooks,
        private readonly \CodeVault\Settings\SettingsRepository $settings
    ) {
    }

    public function attempt(string $username, string $password, string $ip): AuthResult
    {
        $verdict = $this->bruteGuard->preCheck($ip);

        if (!$verdict->allowed) {
            return AuthResult::blocked((string) $verdict->reason);
        }

        $admin = $this->admins->findByUsername($username);

        if ($admin === null) {
            $this->bruteGuard->recordFailedAttempt($ip, $username, userExists: false);
            $this->hooks->fire(HookPoints::ADMIN_LOGIN_FAILED, ['username' => $username, 'ip' => $ip]);

            return AuthResult::invalid();
        }

        if ($this->accountLocks->isLocked((int) $admin['id'])) {
            return AuthResult::locked();
        }

        if (!password_verify($password, $admin['password_hash'])) {
            $outcome = $this->bruteGuard->recordFailedAttempt($ip, $username, userExists: true, adminId: (int) $admin['id']);
            $this->hooks->fire(HookPoints::ADMIN_LOGIN_FAILED, ['username' => $username, 'ip' => $ip, 'adminId' => $admin['id']]);

            return $outcome['accountLocked'] ? AuthResult::locked() : AuthResult::invalid();
        }

        $this->bruteGuard->recordSuccessfulAttempt($ip, $username);

        $is2faGloballyEnabled = $this->settings->get('security.2fa_enabled', '1') === '1';

        if ($is2faGloballyEnabled && (int) $admin['two_factor_enabled'] === 1) {
            // Deliberately no ADMIN_LOGIN hook fire here — the password
            // alone isn't a completed login when 2FA is enabled; that
            // fires from the 2FA-verification step instead, once the
            // second factor is actually confirmed.
            return AuthResult::needsTwoFactor($admin);
        }

        $this->hooks->fire(HookPoints::ADMIN_LOGIN, ['adminId' => $admin['id'], 'username' => $username, 'ip' => $ip]);

        return AuthResult::success($admin);
    }

    /**
     * Verifies an admin's security PIN for BruteGuard recovery.
     * If valid, clears the IP block and account lock.
     */
    public function verifySecurityPin(string $username, string $pin, string $ip): bool
    {
        $admin = $this->admins->findByUsername($username);
        
        if ($admin === null || empty($admin['security_pin_hash'])) {
            return false;
        }

        if (password_verify($pin, $admin['security_pin_hash'])) {
            $this->bruteGuard->clearIpBlock($ip);
            $this->accountLocks->unlock((int) $admin['id']);
            return true;
        }

        return false;
    }
}
