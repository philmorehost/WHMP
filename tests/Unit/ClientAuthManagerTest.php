<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Clients\ClientAuthManager;
use CodeVault\Clients\ClientRepository;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Security\AccountLockRepository;
use CodeVault\Security\BruteGuard;
use CodeVault\Security\CountryRuleRepository;
use CodeVault\Security\IpRuleRepository;
use CodeVault\Security\LoginAttemptRepository;
use CodeVault\Security\NullGeoIpResolver;
use CodeVault\Security\PhpassHasher;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * Login behaviour for clients whose stored hash is a legacy PHPass
 * portable hash ($P$...) imported from WHMCS <= 7.x: the correct
 * password must authenticate, and the hash must be transparently
 * upgraded to Argon2id so every subsequent login uses the native path
 * (and the admin-side password checks work).
 */
final class ClientAuthManagerTest extends DatabaseTestCase
{
    private ClientRepository $clients;
    private ClientAuthManager $auth;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->clients = new ClientRepository($this->db);

        $bruteGuard = new BruteGuard(
            new LoginAttemptRepository($this->db),
            new IpRuleRepository($this->db),
            new CountryRuleRepository($this->db),
            new AccountLockRepository($this->db),
            new NullGeoIpResolver(),
            new HookDispatcher(),
        );

        $this->auth = new ClientAuthManager($this->clients, $bruteGuard, new SettingsRepository($this->db), new PhpassHasher());
    }

    private function createClientWithHash(string $email, string $hash): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->db->insert(
            'INSERT INTO clients (email, password_hash, first_name, last_name, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$email, $hash, 'Imported', 'Client', 'active', $now, $now]
        );
    }

    public function test_phpass_hash_logs_in_with_the_correct_password(): void
    {
        // passlib vector: phpass('test12345') with rounds char '9'
        $this->createClientWithHash('phpass-login@example.test', '$P$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0');

        $result = $this->auth->attempt('phpass-login@example.test', 'test12345', '203.0.113.10');

        $this->assertTrue($result->isSuccess(), 'a legacy phpass hash must authenticate with the client\'s real password');
    }

    public function test_phpass_hash_rejects_a_wrong_password(): void
    {
        $this->createClientWithHash('phpass-wrong@example.test', '$P$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0');

        $result = $this->auth->attempt('phpass-wrong@example.test', 'not-the-password', '203.0.113.11');

        $this->assertFalse($result->isSuccess(), 'a wrong password must be rejected');
    }

    public function test_successful_phpass_login_transparently_upgrades_to_argon2id(): void
    {
        $id = $this->createClientWithHash('phpass-upgrade@example.test', '$P$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0');

        $this->auth->attempt('phpass-upgrade@example.test', 'test12345', '203.0.113.12');

        $client = $this->clients->find($id);
        $this->assertNotSame('$P$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0', $client['password_hash'], 'the phpass hash must be replaced');
        $this->assertTrue(password_verify('test12345', $client['password_hash']), 'the upgraded hash must verify with the same password');
        $this->assertTrue(\str_starts_with($client['password_hash'], '$argon2id$'));
    }

    public function test_empty_hash_cannot_login_even_with_an_empty_password(): void
    {
        $this->createClientWithHash('empty-hash@example.test', '');

        $result = $this->auth->attempt('empty-hash@example.test', '', '203.0.113.13');

        $this->assertFalse($result->isSuccess(), 'an empty stored hash must never authenticate');
    }
}
