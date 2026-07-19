<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Database\Migrator;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\Theme\ThemeSettings;

final class ThemeSettingsTest extends DatabaseTestCase
{
    private ThemeSettings $theme;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->theme = new ThemeSettings(new SettingsRepository($this->db));
    }

    public function test_get_returns_sensible_defaults_when_nothing_saved_yet(): void
    {
        $theme = $this->theme->get();

        $this->assertSame('CodeVault', $theme['brandName']);
        $this->assertNull($theme['logoUrl']);
        $this->assertSame('#2f6fed', $theme['primaryColor']);
    }

    public function test_save_and_get_round_trips(): void
    {
        $this->theme->save('Acme Hosting', 'https://example.test/logo.png', '#ff0000');

        $theme = $this->theme->get();
        $this->assertSame('Acme Hosting', $theme['brandName']);
        $this->assertSame('https://example.test/logo.png', $theme['logoUrl']);
        $this->assertSame('#ff0000', $theme['primaryColor']);
    }

    public function test_save_falls_back_to_default_brand_name_when_blank(): void
    {
        $this->theme->save('', null, '#123456');

        $this->assertSame('CodeVault', $this->theme->get()['brandName']);
    }

    public function test_save_rejects_an_invalid_hex_color_and_falls_back_to_default(): void
    {
        $this->theme->save('Acme', null, 'not-a-color');

        $this->assertSame('#2f6fed', $this->theme->get()['primaryColor']);
    }

    public function test_is_valid_hex(): void
    {
        $this->assertTrue($this->theme->isValidHex('#2f6fed'));
        $this->assertTrue($this->theme->isValidHex('#FFFFFF'));
        $this->assertFalse($this->theme->isValidHex('2f6fed'));
        $this->assertFalse($this->theme->isValidHex('#fff'));
        $this->assertFalse($this->theme->isValidHex('red'));
    }

    public function test_primary_color_dark_is_a_darker_shade_of_the_primary_color(): void
    {
        $this->theme->save('Acme', null, '#ff0000');

        $theme = $this->theme->get();
        $this->assertSame('#ff0000', $theme['primaryColor']);
        $this->assertSame('#d10000', $theme['primaryColorDark']);
    }

    public function test_empty_logo_url_saves_as_null(): void
    {
        $this->theme->save('Acme', 'https://example.test/logo.png', '#2f6fed');
        $this->theme->save('Acme', '', '#2f6fed');

        $this->assertNull($this->theme->get()['logoUrl']);
    }
}
