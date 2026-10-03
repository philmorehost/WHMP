<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Config;
use CodeVault\Container;
use CodeVault\Database;
use CodeVault\Database\Migrator;
use CodeVault\Marketing\TawkToPages;
use CodeVault\Modules\AddonModuleRepository;
use CodeVault\Reseller\CurrentReseller;
use CodeVault\Reseller\ResellerChat;
use CodeVault\Support\App;
use CodeVault\Tests\Support\DatabaseTestCase;
use CodeVault\View;

/**
 * A store's own support chat, and the promise that comes with it.
 *
 * The rule this file exists to pin is negative and absolute: **the platform's chat
 * must never appear on a host that matched a store.** That is not a styling
 * preference — it is the reseller's customer being handed a way to ask US about
 * the reseller's prices, inside the reseller's own shop.
 *
 * So the two tests that matter most are a pair over the SAME configured platform
 * widget: on the platform's own host it renders, and on a store's host it does not.
 * Either one alone proves nothing. And the store-with-nothing-configured case is
 * checked too, because "no chat" is the correct answer there and falling back to
 * ours is exactly the bug.
 *
 * The rest is validation, which is security-relevant rather than cosmetic: the
 * Tawk id is interpolated into a `<script src>` we generate, so it is accepted only
 * when it is id-shaped. A pasted embed block is refused — and recognisably so,
 * because pasting the block is what Tawk's own instructions tell you to do.
 */
final class ResellerChatTest extends DatabaseTestCase
{
    /** Distinctive so its presence or absence in rendered output is unambiguous. */
    private const PLATFORM_WIDGET_MARKER = 'PLATFORM-ONLY-CHATBOX';

    private AddonModuleRepository $addons;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $configDir = sys_get_temp_dir() . '/codevault-reseller-chat-' . uniqid();
        mkdir($configDir);
        $config = new Config($configDir);

        $container = new Container();
        $container->instance(Database::class, $this->db);
        $container->instance(Config::class, $config);
        $container->instance(View::class, new View(dirname(__DIR__, 2) . '/resources/views'));
        $container->instance(CurrentReseller::class, new CurrentReseller());
        App::setContainer($container);

        $this->addons = $container->make(AddonModuleRepository::class);
        $this->addons->activate('tawk-to');
        $this->addons->setConfig('tawk-to', [
            'widget_code' => '<script>/*' . self::PLATFORM_WIDGET_MARKER . '*/</script>',
            'pages' => [TawkToPages::ALL],
        ]);
    }

    // --- the pair that encodes the promise --------------------------------

    public function test_the_platforms_chat_still_renders_on_the_platforms_own_host(): void
    {
        // Without this half, a widget that simply never rendered anywhere would
        // look like the feature working.
        $html = $this->renderChat(null);

        $this->assertStringContainsString(self::PLATFORM_WIDGET_MARKER, $html);
    }

    public function test_the_platforms_chat_never_renders_on_a_stores_host(): void
    {
        // A store WITH its own chat configured. Ours must still not appear: this is
        // the difference between "we showed theirs" and "we showed ours as well".
        $html = $this->renderChat($this->store([
            'support_whatsapp' => '2348012345678',
        ]));

        $this->assertStringNotContainsString(
            self::PLATFORM_WIDGET_MARKER,
            $html,
            'The platform chatbox must never appear on a store domain.'
        );
        $this->assertStringContainsString('wa.me/2348012345678', $html);
    }

    // --- what a store shows ----------------------------------------------

    public function test_a_store_without_a_chat_shows_no_chat_rather_than_ours(): void
    {
        $html = $this->renderChat($this->store([]));

        $this->assertStringNotContainsString(self::PLATFORM_WIDGET_MARKER, $html);
        $this->assertStringNotContainsString('wa.me', $html);
        $this->assertStringNotContainsString('embed.tawk.to', $html);
    }

    public function test_a_stores_own_tawk_widget_replaces_its_whatsapp_button(): void
    {
        $html = $this->renderChat($this->store([
            'support_whatsapp' => '2348012345678',
            'tawk_property_id' => '6a1b2c3d4e5f6a7b8c9d0e1f',
        ]));

        $this->assertStringContainsString('https://embed.tawk.to/6a1b2c3d4e5f6a7b8c9d0e1f/default', $html);
        $this->assertStringNotContainsString('wa.me', $html, 'Tawk.To replaces the WhatsApp button, it does not join it.');
        $this->assertStringNotContainsString(self::PLATFORM_WIDGET_MARKER, $html);

        // The inline loader has to carry the per-response nonce, or script-src
        // blocks it and the store has a widget that silently never loads.
        $this->assertMatchesRegularExpression('~<script[^>]*nonce="[^"]+"~', $html);
    }

    public function test_a_stores_button_uses_its_own_accent_colour(): void
    {
        $html = $this->renderChat($this->store([
            'support_whatsapp' => '2348012345678',
            'primary_color' => '#123456',
        ]));

        $this->assertStringContainsString('#123456', $html);
    }

    // --- the automatic default ---------------------------------------------

    public function test_a_new_store_shows_whatsapp_on_the_owners_phone_automatically(): void
    {
        // Never saved chat settings: the widget must appear anyway, on the number
        // we already have, rather than waiting for a Save nobody knew to press.
        $html = $this->renderChat($this->store([
            'client_id' => $this->owner('+234 801 234 5678'),
            'chat_configured_at' => null,
        ]));

        $this->assertStringContainsString('wa.me/2348012345678', $html);
        $this->assertStringNotContainsString(self::PLATFORM_WIDGET_MARKER, $html);
    }

    public function test_a_store_that_chose_no_chat_is_not_overridden_by_the_fallback(): void
    {
        // Saved with both fields blank = the reseller's explicit choice of "none".
        $html = $this->renderChat($this->store([
            'client_id' => $this->owner('+234 801 234 5678'),
            'chat_configured_at' => '2026-01-01 00:00:00',
        ]));

        $this->assertStringNotContainsString('wa.me', $html);
        $this->assertStringNotContainsString(self::PLATFORM_WIDGET_MARKER, $html);
    }

    public function test_a_saved_number_wins_over_the_owners_phone(): void
    {
        $html = $this->renderChat($this->store([
            'client_id' => $this->owner('+234 801 234 5678'),
            'support_whatsapp' => '447700900123',
            'chat_configured_at' => '2026-01-01 00:00:00',
        ]));

        $this->assertStringContainsString('wa.me/447700900123', $html);
        $this->assertStringNotContainsString('wa.me/2348012345678', $html);
    }

    public function test_the_widget_floats_and_can_be_minimised(): void
    {
        $html = $this->renderChat($this->store(['support_whatsapp' => '2348012345678']));

        $this->assertStringContainsString('position: fixed', $html);
        $this->assertStringContainsString('data-cv-wa-minimize', $html);
        $this->assertStringContainsString('@media (max-width: 480px)', $html);
        // The launcher is a real link, so the store is reachable without JavaScript.
        $this->assertMatchesRegularExpression('~<a class="cv-wa__launcher" href="https://wa\.me/2348012345678~', $html);
        // The behaviour script must carry the nonce or script-src blocks it.
        $this->assertMatchesRegularExpression('~<script nonce="[^"]+">~', $html);
    }

    // --- validation -------------------------------------------------------

    public function test_whatsapp_accepts_the_shapes_people_actually_type(): void
    {
        // A list of pairs, NOT a map keyed by the input: PHP silently turns a
        // numeric-string array key into an int, so `'2348012345678' => ...` would
        // hand normaliseWhatsapp() an integer and blow up on its string type.
        foreach ([
            ['+234 801 234 5678', '2348012345678'],
            ['2348012345678', '2348012345678'],
            ['002348012345678', '2348012345678'],
            ['(234) 801-234-5678', '2348012345678'],
        ] as [$typed, $expected]) {
            $this->assertSame($expected, ResellerChat::normaliseWhatsapp($typed), $typed);
        }

        $this->assertNull(ResellerChat::normaliseWhatsapp(''), 'blank means "not set", not "a number".');
        $this->assertNull(ResellerChat::normaliseWhatsapp('12345'), 'too short to be a real number.');
        $this->assertNull(ResellerChat::normaliseWhatsapp('1234567890123456'), 'longer than E.164 allows.');
    }

    public function test_the_tawk_id_is_accepted_only_when_it_is_id_shaped(): void
    {
        $this->assertSame('6a1b2c3d4e5f', ResellerChat::normaliseTawkProperty(' 6a1b2c3d4e5f '));

        // This value goes inside a <script src> we generate, so anything that could
        // break out of it is refused outright rather than escaped.
        foreach (['abc', 'a/b', 'x"><script>alert(1)</script>', 'a b'] as $hostile) {
            $this->assertNull(ResellerChat::normaliseTawkProperty($hostile), $hostile);
        }

        $this->assertNull(ResellerChat::normaliseTawkProperty(''));
    }

    public function test_a_pasted_embed_block_is_recognised_so_it_can_be_explained(): void
    {
        $pasted = '<script>var Tawk_API=Tawk_API||{};(function(){s1.src="https://embed.tawk.to/abc/default";})();</script>';

        $this->assertTrue(ResellerChat::looksLikePastedEmbed($pasted));
        $this->assertFalse(ResellerChat::looksLikePastedEmbed('6a1b2c3d4e5f'));

        // And it is refused, not stored: pasting the block is what Tawk's own
        // instructions lead you to do, so the refusal has to be specific.
        $this->assertNull(ResellerChat::normaliseTawkProperty($pasted));
    }

    public function test_the_service_refuses_a_pasted_embed_with_an_explanation(): void
    {
        $service = new \CodeVault\Reseller\ResellerStoreService(
            new \CodeVault\Reseller\ResellerStoreRepository($this->db),
            new \CodeVault\Reseller\ResellerStoreLocator(
                new \CodeVault\Reseller\ResellerStoreRepository($this->db),
                new Config(sys_get_temp_dir())
            ),
            new \CodeVault\Reseller\DomainVerifier()
        );

        $result = $service->saveChat(1, [
            'support_whatsapp' => '',
            'tawk_property_id' => '<script>var Tawk_API={};</script>',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('whole embed code', (string) $result['error']);
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function store(array $overrides): array
    {
        return array_merge([
            'id' => 1,
            'slug' => 'acme',
            'status' => 'active',
            'brand_name' => 'Acme Hosting',
            'primary_color' => '#ff8f28',
            'support_whatsapp' => null,
            'tawk_property_id' => null,
            'tawk_widget_id' => null,
        ], $overrides);
    }

    /** A store owner's client row with the given phone; returns the client id. */
    private function owner(string $phone): int
    {
        return (int) $this->db->insert(
            'INSERT INTO clients (email, password_hash, first_name, last_name, phone, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [uniqid('owner', true) . '@example.com', '', 'Store', 'Owner', $phone, 'active', '2026-01-01 00:00:00', '2026-01-01 00:00:00']
        );
    }

    /**
     * Render the chat partial for a store, or for the platform when $store is null.
     *
     * @param array<string, mixed>|null $store
     */
    private function renderChat(?array $store): string
    {
        $reseller = App::container()->make(CurrentReseller::class);
        $reseller->set($store, $store === null ? null : 'shop.example.com');

        return App::container()->make(View::class)->partial('partials.live-chat');
    }
}
