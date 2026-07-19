<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Provisioning\LocalProvisioningModule;
use PHPUnit\Framework\TestCase;

final class LocalProvisioningModuleTest extends TestCase
{
    private string $dir;
    private LocalProvisioningModule $module;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/codevault-local-prov-' . uniqid();
        $this->module = new LocalProvisioningModule($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    public function test_create_succeeds_for_a_new_username(): void
    {
        $result = $this->module->create(['username' => 'testuser1', 'product_name' => 'Starter']);

        $this->assertTrue($result['success']);
    }

    public function test_create_fails_for_a_duplicate_username(): void
    {
        $this->module->create(['username' => 'testuser2']);
        $result = $this->module->create(['username' => 'testuser2']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already exists', $result['message']);
    }

    public function test_suspend_and_unsuspend_round_trip(): void
    {
        $this->module->create(['username' => 'testuser3']);

        $suspend = $this->module->suspend(['username' => 'testuser3']);
        $this->assertTrue($suspend['success']);

        $unsuspend = $this->module->unsuspend(['username' => 'testuser3']);
        $this->assertTrue($unsuspend['success']);
    }

    public function test_suspend_fails_for_unknown_account(): void
    {
        $result = $this->module->suspend(['username' => 'ghost']);

        $this->assertFalse($result['success']);
    }

    public function test_terminate_removes_the_account_so_it_can_be_recreated(): void
    {
        $this->module->create(['username' => 'testuser4']);
        $terminate = $this->module->terminate(['username' => 'testuser4']);
        $this->assertTrue($terminate['success']);

        $recreate = $this->module->create(['username' => 'testuser4']);
        $this->assertTrue($recreate['success'], 'terminate should free the username for reuse');
    }

    public function test_usage_returns_data_for_an_existing_account(): void
    {
        $this->module->create(['username' => 'testuser5']);

        $usage = $this->module->usage(['username' => 'testuser5']);

        $this->assertTrue($usage['success']);
        $this->assertArrayHasKey('diskUsedMb', $usage);
    }

    public function test_sso_fails_for_unknown_account(): void
    {
        $result = $this->module->singleSignOn(['username' => 'ghost']);

        $this->assertFalse($result['success']);
    }
}
