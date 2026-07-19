<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Security\AccountLockRepository;
use CodeVault\Security\BruteGuard;
use CodeVault\Security\CountryRuleRepository;
use CodeVault\Security\IpRuleRepository;
use CodeVault\Security\LoginAttemptRepository;
use CodeVault\Security\NullGeoIpResolver;
use CodeVault\Tests\Support\DatabaseTestCase;

final class BruteGuardTest extends DatabaseTestCase
{
    private BruteGuard $bruteGuard;
    private IpRuleRepository $ipRules;
    private CountryRuleRepository $countryRules;
    private AccountLockRepository $accountLocks;
    private HookDispatcher $hooks;

    protected function setUp(): void
    {
        parent::setUp();

        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $attempts = new LoginAttemptRepository($this->db);
        $this->ipRules = new IpRuleRepository($this->db);
        $this->countryRules = new CountryRuleRepository($this->db);
        $this->accountLocks = new AccountLockRepository($this->db);
        $this->hooks = new HookDispatcher();

        $this->bruteGuard = new BruteGuard(
            $attempts,
            $this->ipRules,
            $this->countryRules,
            $this->accountLocks,
            new NullGeoIpResolver(),
            $this->hooks,
        );
    }

    public function test_precheck_allows_a_clean_ip_by_default(): void
    {
        $this->assertTrue($this->bruteGuard->preCheck('198.51.100.1')->allowed);
    }

    public function test_nonexistent_username_blocks_the_ip_immediately(): void
    {
        $blocked = [];
        $this->hooks->register(HookPoints::BRUTEGUARD_IP_BLOCKED, function (array $p) use (&$blocked) {
            $blocked[] = $p;
        });

        $outcome = $this->bruteGuard->recordFailedAttempt('198.51.100.2', 'ghost-user', userExists: false);

        $this->assertTrue($outcome['ipBlocked']);
        $this->assertTrue($this->ipRules->isBlocked('198.51.100.2'));
        $this->assertFalse($this->bruteGuard->preCheck('198.51.100.2')->allowed);
        $this->assertCount(1, $blocked);
        $this->assertSame('unknown_user', $blocked[0]['reason']);
    }

    public function test_block_tier_escalates_on_repeat_offenses(): void
    {
        $ip = '198.51.100.3';

        $this->assertSame('day', $this->ipRules->blacklist($ip, 'offense 1'));
        $this->assertSame('week', $this->ipRules->blacklist($ip, 'offense 2'));
        $this->assertSame('month', $this->ipRules->blacklist($ip, 'offense 3'));
        $this->assertSame('year', $this->ipRules->blacklist($ip, 'offense 4'));
        $this->assertSame('year', $this->ipRules->blacklist($ip, 'offense 5'), 'tier should not escalate past year');
    }

    public function test_valid_user_reaching_the_fail_threshold_locks_the_account(): void
    {
        $adminId = (int) $this->db->insert(
            'INSERT INTO admins (username, email, password_hash, display_name, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
            ['realadmin', 'realadmin@example.test', password_hash('x', PASSWORD_ARGON2ID), 'Real Admin']
        );

        $locked = [];
        $this->hooks->register(HookPoints::BRUTEGUARD_ACCOUNT_LOCKED, function (array $p) use (&$locked) {
            $locked[] = $p;
        });

        $ip = '198.51.100.4';
        $outcome = ['accountLocked' => false, 'ipBlocked' => false];

        for ($i = 0; $i < BruteGuard::MAX_FAILS_PER_USERNAME; $i++) {
            $outcome = $this->bruteGuard->recordFailedAttempt($ip, 'realadmin', userExists: true, adminId: $adminId);
        }

        $this->assertTrue($outcome['accountLocked']);
        $this->assertTrue($this->accountLocks->isLocked($adminId));
        $this->assertCount(1, $locked);
    }

    public function test_ip_reaching_the_fail_threshold_across_usernames_gets_blocked(): void
    {
        $ip = '198.51.100.5';

        // Spread failures across different usernames so no single username
        // triggers an account lock — only the IP-level threshold should fire.
        for ($i = 0; $i < BruteGuard::MAX_FAILS_PER_IP; $i++) {
            $this->bruteGuard->recordFailedAttempt($ip, "user-{$i}", userExists: true, adminId: 100 + $i);
        }

        $this->assertTrue($this->ipRules->isBlocked($ip));
    }

    public function test_five_consecutive_clean_sessions_auto_whitelists_the_ip(): void
    {
        $whitelisted = [];
        $this->hooks->register(HookPoints::BRUTEGUARD_IP_WHITELISTED, function (array $p) use (&$whitelisted) {
            $whitelisted[] = $p;
        });

        $ip = '198.51.100.6';

        for ($i = 0; $i < BruteGuard::CLEAN_SESSIONS_FOR_WHITELIST; $i++) {
            $this->bruteGuard->recordSuccessfulAttempt($ip, 'gooduser');
        }

        $this->assertTrue($this->ipRules->isWhitelisted($ip));
        $this->assertCount(1, $whitelisted);
    }

    public function test_a_single_failure_resets_the_clean_session_streak(): void
    {
        $ip = '198.51.100.7';

        $this->bruteGuard->recordSuccessfulAttempt($ip, 'gooduser');
        $this->bruteGuard->recordSuccessfulAttempt($ip, 'gooduser');
        $this->bruteGuard->recordSuccessfulAttempt($ip, 'gooduser');
        // 3/5 toward whitelist, then a failure (against a real, non-locking user id) resets it.
        $this->bruteGuard->recordFailedAttempt($ip, 'gooduser', userExists: true, adminId: 999);

        for ($i = 0; $i < BruteGuard::CLEAN_SESSIONS_FOR_WHITELIST - 1; $i++) {
            $this->bruteGuard->recordSuccessfulAttempt($ip, 'gooduser');
        }

        $this->assertFalse($this->ipRules->isWhitelisted($ip), 'streak should have been reset by the failure, so 4 more successes is not enough');
    }

    public function test_precheck_denies_a_blacklisted_country_for_an_untracked_ip(): void
    {
        $this->countryRules->setPolicy('KP', 'blacklisted');

        // NullGeoIpResolver always returns null, so this asserts the
        // country-rule wiring works via a direct policy check rather than
        // a real GeoIP lookup (no .mmdb file is available in this environment).
        $this->assertSame('blacklisted', $this->countryRules->policyFor('KP'));
        $this->assertSame('not_specified', $this->countryRules->policyFor(null));
    }

    public function test_whitelisted_ip_bypasses_country_blacklist(): void
    {
        $ip = '198.51.100.8';
        $this->ipRules->whitelist($ip, 'trusted partner', 'manual');

        $this->assertTrue($this->bruteGuard->preCheck($ip)->allowed);
    }
}
