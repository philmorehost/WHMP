<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Auth\AdminRepository;
use CodeVault\Auth\AuthManager;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Security\AccountLockRepository;
use CodeVault\Security\BruteGuard;
use CodeVault\Security\CountryRuleRepository;
use CodeVault\Security\IpRuleRepository;
use CodeVault\Security\LoginAttemptRepository;
use CodeVault\Security\NullGeoIpResolver;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

final class AuthManagerTest extends DatabaseTestCase
{
    private AuthManager $auth;
    private AccountLockRepository $accountLocks;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->adminId = (int) $this->db->insert(
            'INSERT INTO admins (username, email, password_hash, display_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            ['realadmin', 'admin@example.test', password_hash('correct-horse-battery', PASSWORD_ARGON2ID), 'Real Admin', $now, $now]
        );

        $admins = new AdminRepository($this->db);
        $this->accountLocks = new AccountLockRepository($this->db);
        $hooks = new HookDispatcher();

        $bruteGuard = new BruteGuard(
            new LoginAttemptRepository($this->db),
            new IpRuleRepository($this->db),
            new CountryRuleRepository($this->db),
            $this->accountLocks,
            new NullGeoIpResolver(),
            $hooks,
        );

        $this->auth = new AuthManager($admins, $bruteGuard, $this->accountLocks, $hooks);
    }

    public function test_correct_credentials_succeed(): void
    {
        $result = $this->auth->attempt('realadmin', 'correct-horse-battery', '203.0.113.10');

        $this->assertTrue($result->isSuccess());
        $this->assertSame($this->adminId, (int) $result->admin['id']);
    }

    public function test_wrong_password_is_invalid_but_does_not_leak_which_part_was_wrong(): void
    {
        $result = $this->auth->attempt('realadmin', 'wrong-password', '203.0.113.11');

        $this->assertSame('invalid', $result->status);
    }

    public function test_nonexistent_username_is_invalid_and_blocks_the_ip(): void
    {
        $ip = '203.0.113.12';
        $result = $this->auth->attempt('no-such-admin', 'whatever', $ip);

        $this->assertSame('invalid', $result->status);

        // The next attempt from this IP is blocked outright, even with correct credentials.
        $second = $this->auth->attempt('realadmin', 'correct-horse-battery', $ip);
        $this->assertSame('blocked', $second->status);
    }

    public function test_repeated_wrong_passwords_lock_the_account(): void
    {
        $ip = '203.0.113.13';

        for ($i = 0; $i < BruteGuard::MAX_FAILS_PER_USERNAME; $i++) {
            $result = $this->auth->attempt('realadmin', 'wrong-password', $ip);
        }

        $this->assertSame('locked', $result->status);
        $this->assertTrue($this->accountLocks->isLocked($this->adminId));

        // Even the correct password no longer works while locked.
        $stillLocked = $this->auth->attempt('realadmin', 'correct-horse-battery', $ip);
        $this->assertSame('locked', $stillLocked->status);
    }

    public function test_unlocking_restores_normal_login(): void
    {
        $ip = '203.0.113.14';

        for ($i = 0; $i < BruteGuard::MAX_FAILS_PER_USERNAME; $i++) {
            $this->auth->attempt('realadmin', 'wrong-password', $ip);
        }

        $this->accountLocks->unlock($this->adminId);

        $result = $this->auth->attempt('realadmin', 'correct-horse-battery', $ip);
        $this->assertTrue($result->isSuccess());
    }
}
