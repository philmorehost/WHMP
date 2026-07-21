<?php

declare(strict_types=1);

namespace CodeVault\Security;

use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;

/**
 * BruteGuard core (blueprint §5 — the full anti-brute-force spec):
 * username-track + IP-track, non-existent-user -> immediate IP block,
 * valid-user max-fails -> account lock, tiered IP escalation
 * (day/week/month/year), auto-whitelist after 5 clean sessions, MaxMind
 * country rules. This is the app-level Redis-free gate — a second-layer
 * CSF/nginx firewall sync is a later, optional addition (blueprint's
 * "exec stays disabled" — this class never shells out).
 */
final class BruteGuard
{
    public const MAX_FAILS_PER_USERNAME = 5;
    public const USERNAME_WINDOW_MINUTES = 15;
    public const MAX_FAILS_PER_IP = 10;
    public const IP_WINDOW_MINUTES = 15;
    public const CLEAN_SESSIONS_FOR_WHITELIST = 5;
    public const ACCOUNT_LOCK_MINUTES = 30;

    public function __construct(
        private readonly LoginAttemptRepository $attempts,
        private readonly IpRuleRepository $ipRules,
        private readonly CountryRuleRepository $countryRules,
        private readonly AccountLockRepository $accountLocks,
        private readonly GeoIpResolver $geoIp,
        private readonly HookDispatcher $hooks
    ) {
    }

    /**
     * Called before even attempting to verify credentials — a blocked IP or
     * blacklisted country is rejected without touching the password or the
     * admins table.
     */
    public function preCheck(string $ip): BruteGuardVerdict
    {
        if ($this->ipRules->isWhitelisted($ip)) {
            return BruteGuardVerdict::allow();
        }

        if ($this->ipRules->isBlocked($ip)) {
            return BruteGuardVerdict::deny('ip_blocked');
        }

        $country = $this->geoIp->resolveCountry($ip);

        if ($this->countryRules->policyFor($country) === 'blacklisted') {
            return BruteGuardVerdict::deny('country_blocked');
        }

        return BruteGuardVerdict::allow();
    }

    /**
     * Clears any IP block or tracking history for a specific IP.
     * Used for PIN-based recovery.
     */
    public function clearIpBlock(string $ip): void
    {
        $this->ipRules->clear($ip);
    }

    /**
     * @return array{accountLocked: bool, ipBlocked: bool}
     */
    public function recordFailedAttempt(string $ip, string $username, bool $userExists, ?int $adminId = null): array
    {
        $country = $this->geoIp->resolveCountry($ip);
        $this->attempts->record($username, $ip, $country, false);
        $this->ipRules->resetCleanSessions($ip);

        $outcome = ['accountLocked' => false, 'ipBlocked' => false];

        if (!$userExists) {
            // Enumeration attempt — block the IP immediately, no threshold.
            $tier = $this->ipRules->blacklist($ip, 'Login attempt for non-existent username.');
            $this->hooks->fire(HookPoints::BRUTEGUARD_IP_BLOCKED, ['ip' => $ip, 'tier' => $tier, 'reason' => 'unknown_user']);
            $outcome['ipBlocked'] = true;

            return $outcome;
        }

        if ($adminId !== null) {
            $usernameFails = $this->attempts->countFailuresByUsername($username, self::USERNAME_WINDOW_MINUTES);

            if ($usernameFails >= self::MAX_FAILS_PER_USERNAME && !$this->accountLocks->isLocked($adminId)) {
                $this->accountLocks->lock($adminId, self::ACCOUNT_LOCK_MINUTES, "{$usernameFails} failed attempts within " . self::USERNAME_WINDOW_MINUTES . ' minutes.');
                $this->hooks->fire(HookPoints::BRUTEGUARD_ACCOUNT_LOCKED, ['adminId' => $adminId, 'username' => $username]);
                $outcome['accountLocked'] = true;
            }
        }

        $ipFails = $this->attempts->countFailuresByIp($ip, self::IP_WINDOW_MINUTES);

        if ($ipFails >= self::MAX_FAILS_PER_IP && !$this->ipRules->isBlocked($ip)) {
            $tier = $this->ipRules->blacklist($ip, "{$ipFails} failed attempts within " . self::IP_WINDOW_MINUTES . ' minutes.');
            $this->hooks->fire(HookPoints::BRUTEGUARD_IP_BLOCKED, ['ip' => $ip, 'tier' => $tier, 'reason' => 'ip_threshold']);
            $outcome['ipBlocked'] = true;
        }

        return $outcome;
    }

    public function recordSuccessfulAttempt(string $ip, string $username): void
    {
        $country = $this->geoIp->resolveCountry($ip);
        $this->attempts->record($username, $ip, $country, true);

        $wasWhitelisted = $this->ipRules->isWhitelisted($ip);
        $count = $this->ipRules->recordCleanSession($ip);

        if (!$wasWhitelisted && $count >= self::CLEAN_SESSIONS_FOR_WHITELIST) {
            $this->ipRules->whitelist($ip, "{$count} consecutive clean sessions.");
            $this->hooks->fire(HookPoints::BRUTEGUARD_IP_WHITELISTED, ['ip' => $ip]);
        }
    }
}
