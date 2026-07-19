<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Domains\LocalRegistrarModule;
use PHPUnit\Framework\TestCase;

final class LocalRegistrarModuleTest extends TestCase
{
    private string $dir;
    private LocalRegistrarModule $module;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/codevault-local-registrar-' . uniqid();
        $this->module = new LocalRegistrarModule($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    public function test_register_succeeds_for_a_new_domain(): void
    {
        $result = $this->module->register(['domain' => 'example1.test', 'years' => 1]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('expiryDate', $result);
    }

    public function test_register_fails_for_an_already_registered_domain(): void
    {
        $this->module->register(['domain' => 'example2.test']);
        $result = $this->module->register(['domain' => 'example2.test']);

        $this->assertFalse($result['success']);
    }

    public function test_check_availability_reflects_local_registration_state(): void
    {
        $this->assertTrue($this->module->checkAvailability(['domain' => 'example3.test'])['available']);

        $this->module->register(['domain' => 'example3.test']);

        $this->assertFalse($this->module->checkAvailability(['domain' => 'example3.test'])['available']);
    }

    public function test_renew_extends_expiry_by_the_requested_years(): void
    {
        $registered = $this->module->register(['domain' => 'example4.test', 'years' => 1]);
        $firstExpiry = $registered['expiryDate'];

        $renewed = $this->module->renew(['domain' => 'example4.test', 'years' => 1]);

        $this->assertTrue($renewed['success']);
        $this->assertGreaterThan($firstExpiry, $renewed['expiryDate']);
    }

    public function test_renew_fails_for_unregistered_domain(): void
    {
        $result = $this->module->renew(['domain' => 'ghost.test']);

        $this->assertFalse($result['success']);
    }

    public function test_nameservers_round_trip(): void
    {
        $this->module->register(['domain' => 'example5.test']);

        $this->module->saveNameservers(['domain' => 'example5.test', 'ns1' => 'ns1.host.test', 'ns2' => 'ns2.host.test']);
        $result = $this->module->getNameservers(['domain' => 'example5.test']);

        $this->assertTrue($result['success']);
        $this->assertSame(['ns1.host.test', 'ns2.host.test'], $result['nameservers']);
    }

    public function test_lock_toggle_round_trip(): void
    {
        $this->module->register(['domain' => 'example6.test']);
        $this->assertTrue($this->module->getRegistrarLock(['domain' => 'example6.test'])['locked'], 'registered domains start locked');

        $this->module->setRegistrarLock(['domain' => 'example6.test', 'lock' => false]);
        $this->assertFalse($this->module->getRegistrarLock(['domain' => 'example6.test'])['locked']);
    }

    public function test_id_protection_toggle_round_trip(): void
    {
        $this->module->register(['domain' => 'example7.test']);

        $this->module->enableIdProtection(['domain' => 'example7.test']);
        $this->assertTrue(json_decode(file_get_contents($this->dir . '/example7.test.json'), true)['id_protection']);

        $this->module->disableIdProtection(['domain' => 'example7.test']);
        $this->assertFalse(json_decode(file_get_contents($this->dir . '/example7.test.json'), true)['id_protection']);
    }

    public function test_epp_code_is_deterministic_and_requires_registration(): void
    {
        $this->assertFalse($this->module->getEppCode(['domain' => 'ghost2.test'])['success']);

        $this->module->register(['domain' => 'example8.test']);
        $first = $this->module->getEppCode(['domain' => 'example8.test']);
        $second = $this->module->getEppCode(['domain' => 'example8.test']);

        $this->assertTrue($first['success']);
        $this->assertSame($first['eppCode'], $second['eppCode']);
    }

    public function test_sync_reports_status_and_expiry(): void
    {
        $registered = $this->module->register(['domain' => 'example9.test']);

        $result = $this->module->sync(['domain' => 'example9.test']);

        $this->assertTrue($result['success']);
        $this->assertSame('active', $result['status']);
        $this->assertSame($registered['expiryDate'], $result['expiryDate']);
    }
}
