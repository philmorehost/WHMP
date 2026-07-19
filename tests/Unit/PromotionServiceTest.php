<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\PromotionRepository;
use CodeVault\Billing\PromotionService;
use CodeVault\Database\Migrator;
use CodeVault\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

final class PromotionServiceTest extends DatabaseTestCase
{
    private PromotionRepository $promotions;
    private PromotionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $this->promotions = new PromotionRepository($this->db);
        $this->service = new PromotionService($this->promotions);
    }

    public function test_unknown_code_is_invalid(): void
    {
        $result = $this->service->validate('NOPE', 100.0);

        $this->assertFalse($result['valid']);
        $this->assertSame(0.0, $result['discount']);
    }

    public function test_percentage_discount_is_capped_at_the_subtotal(): void
    {
        $this->promotions->save(['code' => 'HUGE', 'type' => 'percentage', 'value' => 500]);

        $result = $this->service->validate('HUGE', 20.0);

        $this->assertTrue($result['valid']);
        $this->assertSame(20.0, $result['discount']);
    }

    public function test_fixed_discount_is_capped_at_the_subtotal(): void
    {
        $this->promotions->save(['code' => 'BIGFLAT', 'type' => 'fixed', 'value' => 999]);

        $result = $this->service->validate('BIGFLAT', 15.0);

        $this->assertTrue($result['valid']);
        $this->assertSame(15.0, $result['discount']);
    }

    public function test_code_is_matched_case_insensitively(): void
    {
        $this->promotions->save(['code' => 'MixedCase', 'type' => 'fixed', 'value' => 2]);

        $this->assertTrue($this->service->validate('mixedcase', 10.0)['valid']);
        $this->assertTrue($this->service->validate('MIXEDCASE', 10.0)['valid']);
    }

    public function test_inactive_promotion_is_rejected(): void
    {
        $this->promotions->save(['code' => 'OFF', 'type' => 'fixed', 'value' => 1, 'status' => 'inactive']);

        $this->assertFalse($this->service->validate('OFF', 10.0)['valid']);
    }

    public function test_expired_promotion_is_rejected(): void
    {
        $yesterday = (new DateTimeImmutable('-1 day'))->format('Y-m-d');
        $this->promotions->save(['code' => 'OLD', 'type' => 'fixed', 'value' => 1, 'expires_at' => $yesterday]);

        $this->assertFalse($this->service->validate('OLD', 10.0)['valid']);
    }

    public function test_not_yet_started_promotion_is_rejected(): void
    {
        $tomorrow = (new DateTimeImmutable('+1 day'))->format('Y-m-d');
        $this->promotions->save(['code' => 'FUTURE', 'type' => 'fixed', 'value' => 1, 'starts_at' => $tomorrow]);

        $this->assertFalse($this->service->validate('FUTURE', 10.0)['valid']);
    }

    public function test_promotion_within_its_active_window_is_accepted(): void
    {
        $yesterday = (new DateTimeImmutable('-1 day'))->format('Y-m-d');
        $tomorrow = (new DateTimeImmutable('+1 day'))->format('Y-m-d');
        $this->promotions->save(['code' => 'CURRENT', 'type' => 'fixed', 'value' => 1, 'starts_at' => $yesterday, 'expires_at' => $tomorrow]);

        $this->assertTrue($this->service->validate('CURRENT', 10.0)['valid']);
    }

    public function test_promotion_at_its_redemption_cap_is_rejected(): void
    {
        $this->promotions->save(['code' => 'CAPPED', 'type' => 'fixed', 'value' => 1, 'max_redemptions' => 2]);
        $promotion = $this->promotions->findByCode('CAPPED');

        $this->promotions->incrementRedemptions((int) $promotion['id']);
        $this->assertTrue($this->service->validate('CAPPED', 10.0)['valid']);

        $this->promotions->incrementRedemptions((int) $promotion['id']);
        $this->assertFalse($this->service->validate('CAPPED', 10.0)['valid']);
    }

    public function test_save_upserts_by_code_without_duplicating(): void
    {
        $this->promotions->save(['code' => 'DUPE', 'type' => 'percentage', 'value' => 10]);
        $this->promotions->save(['code' => 'dupe', 'type' => 'percentage', 'value' => 20]);

        $all = $this->promotions->all();
        $this->assertCount(1, $all);
        $this->assertEqualsWithDelta(20.0, (float) $all[0]['value'], 0.001);
    }
}
