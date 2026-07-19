<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Database\Migrator;
use CodeVault\Localization\LanguageRepository;
use CodeVault\Localization\LocalizationService;
use CodeVault\Localization\TranslationOverrideRepository;
use CodeVault\Tests\Support\DatabaseTestCase;

final class LocalizationServiceTest extends DatabaseTestCase
{
    private LanguageRepository $languages;
    private TranslationOverrideRepository $overrides;
    private LocalizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->languages = new LanguageRepository($this->db);
        $this->overrides = new TranslationOverrideRepository($this->db);
        $this->service = new LocalizationService($this->languages, $this->overrides, dirname(__DIR__, 2) . '/resources/lang');
    }

    public function test_default_language_is_the_seeded_english_row(): void
    {
        $default = $this->languages->default();

        $this->assertSame('en', $default['code']);
        $this->assertSame(1, (int) $default['is_default']);
    }

    public function test_active_returns_only_active_languages(): void
    {
        $es = $this->languages->findByCode('es');
        $this->languages->setActive((int) $es['id'], false);

        $codes = array_column($this->languages->active(), 'code');

        $this->assertNotContains('es', $codes);
        $this->assertContains('en', $codes);
        $this->assertContains('ar', $codes);
    }

    public function test_resolve_for_client_falls_back_to_default_when_no_preference(): void
    {
        $language = $this->service->resolveForClient(['language_id' => null]);

        $this->assertSame('en', $language['code']);
    }

    public function test_resolve_for_client_uses_saved_preference(): void
    {
        $es = $this->languages->findByCode('es');

        $language = $this->service->resolveForClient(['language_id' => $es['id']]);

        $this->assertSame('es', $language['code']);
    }

    public function test_resolve_for_client_ignores_an_inactive_saved_preference(): void
    {
        $es = $this->languages->findByCode('es');
        $this->languages->setActive((int) $es['id'], false);

        $language = $this->service->resolveForClient(['language_id' => $es['id']]);

        $this->assertSame('en', $language['code'], 'an inactive language must never surface, even if saved on the client');
    }

    public function test_resolve_effective_prefers_session_over_client_preference(): void
    {
        $es = $this->languages->findByCode('es');
        $ar = $this->languages->findByCode('ar');

        $language = $this->service->resolveEffective(['language_id' => $es['id']], (int) $ar['id']);

        $this->assertSame('ar', $language['code']);
    }

    public function test_translation_for_loads_the_file_catalog(): void
    {
        $t = $this->service->translationFor($this->languages->findByCode('es'));

        $this->assertSame('es', $t->code());
        $this->assertSame('Tienda', $t->get('store.title'));
        $this->assertFalse($t->isRtl());
        $this->assertSame('ltr', $t->dir());
    }

    public function test_translation_for_arabic_is_flagged_rtl(): void
    {
        $t = $this->service->translationFor($this->languages->findByCode('ar'));

        $this->assertTrue($t->isRtl());
        $this->assertSame('rtl', $t->dir());
    }

    public function test_get_falls_back_to_the_key_itself_when_missing_from_the_catalog(): void
    {
        $t = $this->service->translationFor($this->languages->default());

        $this->assertSame('nonexistent.key', $t->get('nonexistent.key'));
    }

    public function test_db_override_wins_over_the_file_catalog_default(): void
    {
        $en = $this->languages->default();
        $this->overrides->set((int) $en['id'], 'store.title', 'Custom Store Name');

        $t = $this->service->translationFor($en);

        $this->assertSame('Custom Store Name', $t->get('store.title'));
    }

    public function test_get_supports_placeholder_replacement(): void
    {
        $en = $this->languages->default();
        $this->overrides->set((int) $en['id'], 'greeting', 'Hello, :name!');

        $t = $this->service->translationFor($en);

        $this->assertSame('Hello, Alice!', $t->get('greeting', ['name' => 'Alice']));
    }

    public function test_set_default_moves_default_and_forces_the_new_default_active(): void
    {
        $es = $this->languages->findByCode('es');
        $this->languages->setActive((int) $es['id'], false);

        $this->languages->setDefault((int) $es['id']);

        $default = $this->languages->default();
        $this->assertSame('es', $default['code']);
        $this->assertSame(1, (int) $default['is_active'], 'the default language must always be active');

        $en = $this->languages->findByCode('en');
        $this->assertSame(0, (int) $en['is_default']);
    }
}
