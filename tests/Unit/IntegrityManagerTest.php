<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Integrity\IntegrityManager;
use CodeVault\Integrity\IntegrityStatus;
use CodeVault\Integrity\IntegrityTokenCipher;
use CodeVault\Tests\Fixtures\FakeIntegrityHttpClient;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

final class IntegrityManagerTest extends DatabaseTestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();

        $definition = require dirname(__DIR__, 2) . '/database/migrations/0003_create_license_activation_table.php';
        $this->db->connection()->exec($definition['up'][0]);
        $this->db->connection()->exec('RENAME TABLE license_activation TO system_activation');

        $this->storageDir = sys_get_temp_dir() . '/codevault-integrity-test-' . uniqid();
        mkdir($this->storageDir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (isset($this->storageDir) && is_dir($this->storageDir)) {
            array_map('unlink', glob($this->storageDir . '/*') ?: []);
            rmdir($this->storageDir);
        }
    }

    private function manager(FakeIntegrityHttpClient $http): IntegrityManager
    {
        return new IntegrityManager(
            $this->db,
            $http,
            new IntegrityTokenCipher('test-key-thats-long-enough-123456'),
            'example.test',
            'https://manager.pmhserver.name.ng/api.php',
            $this->storageDir . '/activation.token',
            $this->storageDir . '/.integrity.lock',
        );
    }

    public function test_pending_when_no_activation_key_has_been_stored(): void
    {
        $http = new FakeIntegrityHttpClient(['ok' => true, 'status' => 200, 'body' => ['valid' => true]]);
        $manager = $this->manager($http);

        $result = $manager->check();

        $this->assertSame(IntegrityStatus::Pending, $result['status']);
        $this->assertSame(0, $http->calls, 'should never call the remote server with no key on file');
    }

    public function test_active_when_server_confirms_the_key_is_valid(): void
    {
        $http = new FakeIntegrityHttpClient(['ok' => true, 'status' => 200, 'body' => ['valid' => true]]);
        $manager = $this->manager($http);
        $manager->storeActivationKey('CV-TEST-KEY');

        $result = $manager->check();

        $this->assertSame(IntegrityStatus::Active, $result['status']);
        $this->assertSame(1, $http->calls);
    }

    public function test_cached_result_is_reused_within_the_ttl_without_calling_the_server_again(): void
    {
        $http = new FakeIntegrityHttpClient(['ok' => true, 'status' => 200, 'body' => ['valid' => true]]);
        $manager = $this->manager($http);
        $manager->storeActivationKey('CV-TEST-KEY');

        $manager->check();
        $second = $manager->check();

        $this->assertSame(IntegrityStatus::Active, $second['status']);
        $this->assertSame(1, $http->calls, 'second check within the cache TTL should not hit the network');
    }

    public function test_suspended_immediately_when_server_explicitly_rejects_the_key(): void
    {
        $http = new FakeIntegrityHttpClient(['ok' => true, 'status' => 200, 'body' => ['valid' => false, 'message' => 'revoked']]);
        $manager = $this->manager($http);
        $manager->storeActivationKey('CV-TEST-KEY');

        $result = $manager->check();

        $this->assertSame(IntegrityStatus::Suspended, $result['status']);
    }

    public function test_grace_when_server_unreachable_but_recently_valid(): void
    {
        $okHttp = new FakeIntegrityHttpClient(['ok' => true, 'status' => 200, 'body' => ['valid' => true]]);
        $manager = $this->manager($okHttp);
        $manager->storeActivationKey('CV-TEST-KEY');
        $manager->check(); // establishes last_valid_at = now

        // Force past the cache TTL so the next check actually calls out again.
        $this->db->update('UPDATE system_activation SET last_checked_at = ? WHERE domain = ?', [
            (new DateTimeImmutable('-7 hours'))->format('Y-m-d H:i:s'),
            'example.test',
        ]);

        $downHttp = new FakeIntegrityHttpClient(['ok' => false, 'status' => 0, 'body' => []]);
        $managerDown = $this->manager($downHttp);

        $result = $managerDown->check();

        $this->assertSame(IntegrityStatus::Grace, $result['status']);
        $this->assertNotNull($result['graceEndsAt']);
    }

    public function test_suspended_when_server_unreachable_and_grace_period_expired(): void
    {
        $okHttp = new FakeIntegrityHttpClient(['ok' => true, 'status' => 200, 'body' => ['valid' => true]]);
        $manager = $this->manager($okHttp);
        $manager->storeActivationKey('CV-TEST-KEY');
        $manager->check();

        // Both the cache window and the 48h grace window have long passed.
        $this->db->update('UPDATE system_activation SET last_checked_at = ?, last_valid_at = ? WHERE domain = ?', [
            (new DateTimeImmutable('-10 days'))->format('Y-m-d H:i:s'),
            (new DateTimeImmutable('-10 days'))->format('Y-m-d H:i:s'),
            'example.test',
        ]);

        $downHttp = new FakeIntegrityHttpClient(['ok' => false, 'status' => 0, 'body' => []]);
        $managerDown = $this->manager($downHttp);

        $result = $managerDown->check();

        $this->assertSame(IntegrityStatus::Suspended, $result['status']);
    }

    public function test_kill_switch_short_circuits_to_suspended_without_calling_the_server(): void
    {
        $http = new FakeIntegrityHttpClient(['ok' => true, 'status' => 200, 'body' => ['valid' => true]]);
        $manager = $this->manager($http);
        $manager->storeActivationKey('CV-TEST-KEY');
        $manager->kill();

        $result = $manager->check();

        $this->assertSame(IntegrityStatus::Suspended, $result['status']);
        $this->assertSame(0, $http->calls);

        $manager->release();
        $this->assertFalse($manager->isKilled());
    }

    public function test_store_and_read_activation_key_round_trips_through_encryption(): void
    {
        $http = new FakeIntegrityHttpClient(['ok' => true, 'status' => 200, 'body' => ['valid' => true]]);
        $manager = $this->manager($http);

        $manager->storeActivationKey('CV-SUPER-SECRET');

        $this->assertSame('CV-SUPER-SECRET', $manager->activationKey());
    }
}
