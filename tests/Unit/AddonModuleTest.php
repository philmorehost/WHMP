<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Config;
use CodeVault\Database\Migrator;
use CodeVault\Hooks\HookDispatcher;
use CodeVault\Hooks\HookPoints;
use CodeVault\Modules\AddonModule;
use CodeVault\Modules\AddonModuleRepository;
use CodeVault\Modules\AddonModuleService;
use CodeVault\Modules\Addons\SystemDiagnosticsAddon;
use CodeVault\Modules\Addons\TawkToAddon;
use CodeVault\Modules\ModuleManager;
use CodeVault\Marketing\TawkToPages;
use CodeVault\Tests\Support\DatabaseTestCase;

final class AddonModuleTest extends DatabaseTestCase
{
    private AddonModuleRepository $repository;
    private string $scratchDir;
    private Migrator $migrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrator = new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations');
        $this->migrator->run();

        $this->repository = new AddonModuleRepository($this->db);
        $this->scratchDir = sys_get_temp_dir() . '/cv_addon_test_' . uniqid();
        mkdir($this->scratchDir . '/storage/cache', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->scratchDir);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }

    // --- AddonModuleRepository --------------------------------------------

    public function test_a_new_slug_is_inactive_by_default(): void
    {
        $this->assertFalse($this->repository->isActive('never-seen'));
    }

    public function test_activate_then_deactivate_round_trips_correctly(): void
    {
        $this->repository->activate('demo-addon');
        $this->assertTrue($this->repository->isActive('demo-addon'));

        $row = $this->repository->find('demo-addon');
        $this->assertNotNull($row['activated_at']);

        $this->repository->deactivate('demo-addon');
        $this->assertFalse($this->repository->isActive('demo-addon'));
    }

    public function test_config_round_trips_as_json(): void
    {
        $this->repository->setConfig('demo-addon', ['threshold' => 5, 'label' => 'x']);

        $this->assertSame(['threshold' => 5, 'label' => 'x'], $this->repository->getConfig('demo-addon'));
    }

    public function test_get_config_for_unknown_slug_is_empty_array(): void
    {
        $this->assertSame([], $this->repository->getConfig('unknown'));
    }

    // --- AddonModuleService: activation gates hook wiring ------------------

    public function test_boot_active_addons_only_wires_hooks_for_activated_slugs(): void
    {
        $hooks = new HookDispatcher();
        $modules = new ModuleManager($hooks);

        $fakeAddon = new class implements AddonModule {
            public function metadata(): array
            {
                return ['name' => 'Fake', 'description' => '', 'version' => '1.0.0', 'author' => 'Test'];
            }

            public function configOptions(): array
            {
                return [];
            }

            public function activate(): array
            {
                return ['success' => true, 'message' => 'ok'];
            }

            public function deactivate(): array
            {
                return ['success' => true, 'message' => 'ok'];
            }

            public function hooks(): array
            {
                return [HookPoints::CRON_JOB_FINISHED => fn (array $p) => 'fired'];
            }

            public function render(array $params): string
            {
                return '';
            }
        };

        $modules->register(AddonModule::class, 'fake', $fakeAddon);
        $service = new AddonModuleService($modules, $this->repository, $hooks);

        // Registered but never activated — boot must NOT wire its hook.
        $service->bootActiveAddons();
        $this->assertFalse($hooks->has(HookPoints::CRON_JOB_FINISHED));

        // Activate, boot again — now it must be wired.
        $service->activate('fake');
        $service->bootActiveAddons();
        $this->assertTrue($hooks->has(HookPoints::CRON_JOB_FINISHED));
    }

    public function test_deactivating_a_never_wired_addon_still_persists_inactive_state(): void
    {
        $hooks = new HookDispatcher();
        $modules = new ModuleManager($hooks);
        $service = new AddonModuleService($modules, $this->repository, $hooks);

        $result = $service->deactivate('unknown-slug');

        $this->assertFalse($result['success']);
    }

    public function test_catalog_reflects_activation_state(): void
    {
        $hooks = new HookDispatcher();
        $modules = new ModuleManager($hooks);

        $addon = new SystemDiagnosticsAddon($this->db, new Config($this->scratchDir), $this->scratchDir, $this->migrator);
        $modules->register(AddonModule::class, 'system-diagnostics', $addon);

        $service = new AddonModuleService($modules, $this->repository, $hooks);

        $catalog = $service->catalog();
        $this->assertCount(1, $catalog);
        $this->assertFalse($catalog[0]['active']);

        $service->activate('system-diagnostics');
        $catalog = $service->catalog();
        $this->assertTrue($catalog[0]['active']);
    }

    // --- SystemDiagnosticsAddon ---------------------------------------------

    public function test_render_reports_live_database_connectivity_as_ok(): void
    {
        $addon = new SystemDiagnosticsAddon($this->db, new Config($this->scratchDir), $this->scratchDir, $this->migrator);

        $html = $addon->render([]);

        $this->assertStringContainsString('Environment', $html);
        $this->assertStringContainsString('MariaDB/MySQL', $html);
        $this->assertStringContainsString('cv-badge--success', $html);
    }

    public function test_render_shows_no_cron_runs_message_when_state_file_absent(): void
    {
        $addon = new SystemDiagnosticsAddon($this->db, new Config($this->scratchDir), $this->scratchDir, $this->migrator);

        $html = $addon->render([]);

        $this->assertStringContainsString('No cron runs recorded yet', $html);
    }

    public function test_render_shows_cron_last_run_timestamps_from_state_file(): void
    {
        file_put_contents(
            $this->scratchDir . '/storage/cache/cron-state.json',
            json_encode(['daily-backup' => 1750000000])
        );

        $addon = new SystemDiagnosticsAddon($this->db, new Config($this->scratchDir), $this->scratchDir, $this->migrator);
        $html = $addon->render([]);

        $this->assertStringContainsString('daily-backup', $html);
        $this->assertStringContainsString(date('Y-m-d H:i:s', 1750000000), $html);
    }

    public function test_hook_listener_records_a_cron_failure_and_render_shows_it(): void
    {
        $addon = new SystemDiagnosticsAddon($this->db, new Config($this->scratchDir), $this->scratchDir, $this->migrator);
        $listener = $addon->hooks()[HookPoints::CRON_JOB_FINISHED];

        $listener(['job' => 'daily-backup', 'result' => ['ran' => false, 'error' => 'disk full']]);

        $html = $addon->render([]);
        $this->assertStringContainsString('daily-backup', $html);
        $this->assertStringContainsString('disk full', $html);
    }

    public function test_hook_listener_ignores_successful_runs(): void
    {
        $addon = new SystemDiagnosticsAddon($this->db, new Config($this->scratchDir), $this->scratchDir, $this->migrator);
        $listener = $addon->hooks()[HookPoints::CRON_JOB_FINISHED];

        $listener(['job' => 'daily-backup', 'result' => ['ran' => true]]);

        $html = $addon->render([]);
        $this->assertStringContainsString('No recorded cron failures', $html);
    }

    public function test_failure_log_caps_at_twenty_entries(): void
    {
        $addon = new SystemDiagnosticsAddon($this->db, new Config($this->scratchDir), $this->scratchDir, $this->migrator);
        $listener = $addon->hooks()[HookPoints::CRON_JOB_FINISHED];

        for ($i = 0; $i < 25; $i++) {
            $listener(['job' => "job-{$i}", 'result' => ['ran' => false, 'error' => "err-{$i}"]]);
        }

        $path = $this->scratchDir . '/storage/cache/addon-diagnostics-failures.json';
        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertCount(20, $decoded);
        $this->assertSame('job-24', $decoded[array_key_last($decoded)]['job']);
        $this->assertSame('job-5', $decoded[0]['job']);
    }

    // --- TawkToPages ---------------------------------------------------------

    public function test_tawk_page_resolver_maps_paths_to_keys(): void
    {
        $this->assertSame('home', TawkToPages::keyForPath('/'));
        $this->assertSame('store', TawkToPages::keyForPath('/store'));
        $this->assertSame('cart', TawkToPages::keyForPath('/cart'));
        $this->assertSame('domains', TawkToPages::keyForPath('/domains/register'));
        $this->assertSame('kb', TawkToPages::keyForPath('/kb'));
        $this->assertSame('downloads', TawkToPages::keyForPath('/downloads'));
        $this->assertSame('client', TawkToPages::keyForPath('/client/dashboard'));
        $this->assertSame('admin', TawkToPages::keyForPath('/admin/addons/tawk-to'));
    }

    // --- TawkToAddon ---------------------------------------------------------

    public function test_tawk_addon_render_offers_widget_code_and_all_pages_choices(): void
    {
        $addon = new TawkToAddon($this->repository);

        $html = $addon->render(['csrf_field' => '<input type="hidden" name="_token" value="x">']);

        $this->assertStringContainsString('Tawk.To embed code', $html);
        $this->assertStringContainsString('value="all"', $html);
        $this->assertStringContainsString('All pages', $html);
        $this->assertStringContainsString('Save Settings', $html);
    }

    public function test_tawk_addon_render_saves_widget_code_and_pages(): void
    {
        $addon = new TawkToAddon($this->repository);

        $html = $addon->render([
            'csrf_field' => '<input type="hidden" name="_token" value="x">',
            'save' => '1',
            'widget_code' => '<script type="text/javascript">var Tawk_API = {};</script>',
            'pages' => ['home', 'store'],
        ]);

        $config = $this->repository->getConfig('tawk-to');
        $this->assertSame(['home', 'store'], $config['pages']);
        $this->assertStringContainsString('var Tawk_API', $config['widget_code']);
        $this->assertStringContainsString('settings saved', $html);
    }

    public function test_tawk_addon_render_all_pages_overrides_individual_selection(): void
    {
        $addon = new TawkToAddon($this->repository);

        $addon->render([
            'csrf_field' => '<input type="hidden" name="_token" value="x">',
            'save' => '1',
            'widget_code' => '<script>tawk();</script>',
            'pages' => ['all', 'home'],
        ]);

        $config = $this->repository->getConfig('tawk-to');
        $this->assertSame(['all'], $config['pages']);
    }
}
