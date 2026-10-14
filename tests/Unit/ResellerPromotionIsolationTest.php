<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\PromotionRepository;
use CodeVault\Billing\PromotionService;
use CodeVault\Database\Migrator;
use CodeVault\Marketing\PromoBannerRepository;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * Promo codes and promo banners belong to exactly one site (migration 0204).
 *
 * The bug this pins: the platform's discount popup appeared on every reseller's
 * website, and the platform's coupon was honoured at every reseller's checkout.
 * Each rule is tested from BOTH sides — "the owner sees it" and "nobody else does"
 * — because an isolation check tested only on the refusing side passes just as
 * well when the feature simply never works.
 */
final class ResellerPromotionIsolationTest extends DatabaseTestCase
{
    private PromotionRepository $promotions;
    private PromotionService $service;
    private PromoBannerRepository $banners;
    private int $storeA;
    private int $storeB;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->promotions = new PromotionRepository($this->db);
        $this->service = new PromotionService($this->promotions);
        $this->banners = new PromoBannerRepository($this->db);

        $this->storeA = $this->store('alpha');
        $this->storeB = $this->store('bravo');
    }

    // ------------------------------------------------------------ promo codes

    public function test_a_platform_code_works_on_the_platform_but_not_on_a_store(): void
    {
        $this->promotions->save(['code' => 'PLATFORM20', 'type' => 'percentage', 'value' => 20]);

        $this->assertTrue($this->service->validate('PLATFORM20', 100.0)['valid']);
        $this->assertFalse(
            $this->service->validate('PLATFORM20', 100.0, $this->storeA)['valid'],
            'the platform\'s coupon must not discount a reseller\'s prices'
        );
    }

    public function test_a_store_code_works_only_on_that_store(): void
    {
        $this->promotions->save(['code' => 'ALPHA10', 'type' => 'percentage', 'value' => 10], $this->storeA);

        $this->assertTrue($this->service->validate('ALPHA10', 100.0, $this->storeA)['valid']);
        $this->assertFalse($this->service->validate('ALPHA10', 100.0, $this->storeB)['valid'], 'not at another store');
        $this->assertFalse($this->service->validate('ALPHA10', 100.0)['valid'], 'not on the platform');
    }

    public function test_two_stores_can_each_run_the_same_code_independently(): void
    {
        $this->promotions->save(['code' => 'SAVE10', 'type' => 'percentage', 'value' => 10], $this->storeA);
        $this->promotions->save(['code' => 'SAVE10', 'type' => 'fixed', 'value' => 3], $this->storeB);

        $this->assertSame(10.0, $this->service->validate('SAVE10', 100.0, $this->storeA)['discount']);
        $this->assertSame(3.0, $this->service->validate('SAVE10', 100.0, $this->storeB)['discount']);

        // Saving one store's SAVE10 again updates only that store's row.
        $this->promotions->save(['code' => 'SAVE10', 'type' => 'percentage', 'value' => 50], $this->storeA);
        $this->assertSame(50.0, $this->service->validate('SAVE10', 100.0, $this->storeA)['discount']);
        $this->assertSame(3.0, $this->service->validate('SAVE10', 100.0, $this->storeB)['discount']);
    }

    public function test_listing_and_deleting_are_scoped(): void
    {
        $this->promotions->save(['code' => 'PLAT', 'type' => 'percentage', 'value' => 5]);
        $this->promotions->save(['code' => 'ALPHA', 'type' => 'percentage', 'value' => 5], $this->storeA);

        $this->assertSame(['PLAT'], array_column($this->promotions->all(), 'code'));
        $this->assertSame(['ALPHA'], array_column($this->promotions->all($this->storeA), 'code'));
        $this->assertSame([], $this->promotions->all($this->storeB));

        $alpha = $this->promotions->findByCode('ALPHA', $this->storeA);
        $this->assertNotNull($alpha);

        $this->assertNull($this->promotions->findForReseller((int) $alpha['id'], $this->storeB));
        $this->assertFalse($this->promotions->deleteForReseller((int) $alpha['id'], $this->storeB), 'store B cannot delete A\'s code');

        // The platform's admin delete never reaches a store's code either.
        $this->promotions->delete((int) $alpha['id']);
        $this->assertNotNull($this->promotions->findByCode('ALPHA', $this->storeA));

        $this->assertTrue($this->promotions->deleteForReseller((int) $alpha['id'], $this->storeA));
        $this->assertNull($this->promotions->findByCode('ALPHA', $this->storeA));
    }

    // ---------------------------------------------------------- promo banners

    public function test_the_platform_banner_never_appears_on_a_store(): void
    {
        $this->banner('Platform sale', null);

        $this->assertNotNull($this->banners->activeForPage('store'), 'the platform still sees its own banner');
        $this->assertNull(
            $this->banners->activeForPage('store', $this->storeA),
            'a store with no banner of its own shows none — never the platform\'s'
        );
    }

    public function test_each_store_sees_only_its_own_banner(): void
    {
        $this->banner('Platform sale', null);
        $this->banner('Alpha sale', $this->storeA);
        $this->banner('Bravo sale', $this->storeB);

        $this->assertSame('Platform sale', $this->banners->activeForPage('home')['name'] ?? null);
        $this->assertSame('Alpha sale', $this->banners->activeForPage('home', $this->storeA)['name'] ?? null);
        $this->assertSame('Bravo sale', $this->banners->activeForPage('home', $this->storeB)['name'] ?? null);
    }

    public function test_a_store_cannot_touch_another_sites_banner(): void
    {
        $alpha = $this->banner('Alpha sale', $this->storeA);
        $platform = $this->banner('Platform sale', null);

        $this->assertNull($this->banners->findScoped($alpha, $this->storeB));
        $this->assertNull($this->banners->findScoped($platform, $this->storeA));
        $this->assertFalse($this->banners->setStatus($alpha, 'paused', $this->storeB));
        $this->assertFalse($this->banners->delete($platform, $this->storeA));
        $this->assertFalse($this->banners->delete($alpha, null), 'the admin page cannot delete a store\'s banner');

        $this->assertNotNull($this->banners->activeForPage('home', $this->storeA), 'still live after the attempts');

        $this->assertTrue($this->banners->setStatus($alpha, 'paused', $this->storeA));
        $this->assertNull($this->banners->activeForPage('home', $this->storeA));
    }

    // ----------------------------------------------------------------- helpers

    private function store(string $slug): int
    {
        $now = '2026-01-01 00:00:00';
        $clientId = (int) $this->db->insert(
            'INSERT INTO clients (email, password_hash, first_name, last_name, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$slug . '@example.com', '', ucfirst($slug), 'Owner', 'active', $now, $now]
        );

        return (int) $this->db->insert(
            'INSERT INTO resellers (client_id, slug, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$clientId, $slug, 'active', $now, $now]
        );
    }

    private function banner(string $name, ?int $storeId): int
    {
        return $this->banners->create([
            'name' => $name,
            'template' => 'sunset',
            'eyebrow_text' => null,
            'headline' => $name,
            'subtext' => null,
            'coupon_code' => 'ANY',
            'cta_text' => 'Apply Now',
            'target_pages' => '["all"]',
            'status' => 'active',
            'starts_at' => null,
            'expires_at' => null,
        ], $storeId);
    }
}
