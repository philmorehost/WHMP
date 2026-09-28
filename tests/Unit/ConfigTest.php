<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string $tempDir;

    /** @var array<string, string|false> */
    private array $envBackup = [];

    /** The keys this test asserts on, and therefore has to own. */
    private const ISOLATED_KEYS = ['APP_NAME', 'APP_DEBUG', 'FEATURE_DISABLED', 'NOTHING_HERE'];

    protected function setUp(): void
    {
        // Config::loadEnv() never overrides a key that is ALREADY in the process
        // environment. That is correct dotenv behaviour -- a real environment
        // should beat a .env file -- but it makes this test order-dependent: once
        // any other test boots a Kernel, the application's own .env is in the
        // process and this test's temp .env can no longer win, so APP_NAME came
        // back as the application's value instead of the fixture's. Clear the keys
        // it asserts, and restore them afterwards so this test does not in turn
        // become the one leaking.
        foreach (self::ISOLATED_KEYS as $key) {
            $this->envBackup[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key]);
        }

        $this->tempDir = sys_get_temp_dir() . '/codevault-config-test-' . uniqid();
        mkdir($this->tempDir . '/config', 0777, true);

        file_put_contents($this->tempDir . '/.env', <<<ENV
            APP_NAME="CodeVault Test"
            APP_DEBUG=true
            FEATURE_DISABLED=false
            NOTHING_HERE=null
            ENV);

        file_put_contents($this->tempDir . '/config/app.php', <<<'PHP'
            <?php
            return [
                'name' => 'CodeVault',
                'billing' => [
                    'proration_mode' => 'prorata',
                ],
            ];
            PHP);
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value !== false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            } else {
                putenv($key);
                unset($_ENV[$key]);
            }
        }

        @unlink($this->tempDir . '/.env');
        @unlink($this->tempDir . '/config/app.php');
        @rmdir($this->tempDir . '/config');
        @rmdir($this->tempDir);
    }

    public function test_env_reads_and_type_casts_dotenv_values(): void
    {
        $config = new Config($this->tempDir);

        $this->assertSame('CodeVault Test', $config->env('APP_NAME'));
        $this->assertTrue($config->env('APP_DEBUG'));
        $this->assertFalse($config->env('FEATURE_DISABLED'));
        $this->assertNull($config->env('NOTHING_HERE'));
    }

    public function test_env_returns_default_for_missing_key(): void
    {
        $config = new Config($this->tempDir);

        $this->assertSame('fallback', $config->env('DOES_NOT_EXIST', 'fallback'));
    }

    public function test_get_reads_nested_config_with_dot_notation(): void
    {
        $config = new Config($this->tempDir);

        $this->assertSame('CodeVault', $config->get('app.name'));
        $this->assertSame('prorata', $config->get('app.billing.proration_mode'));
    }

    public function test_get_returns_default_when_path_is_missing(): void
    {
        $config = new Config($this->tempDir);

        $this->assertSame('n/a', $config->get('app.billing.tax_mode', 'n/a'));
    }

    public function test_set_overrides_a_nested_value(): void
    {
        $config = new Config($this->tempDir);
        $config->set('app.billing.proration_mode', 'full');

        $this->assertSame('full', $config->get('app.billing.proration_mode'));
    }
}
