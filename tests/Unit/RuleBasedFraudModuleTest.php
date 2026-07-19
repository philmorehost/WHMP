<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Fraud\RuleBasedFraudModule;
use PHPUnit\Framework\TestCase;

final class RuleBasedFraudModuleTest extends TestCase
{
    private RuleBasedFraudModule $module;

    protected function setUp(): void
    {
        $this->module = new RuleBasedFraudModule(highValueThreshold: 500.0, newAccountMinutes: 30);
    }

    public function test_low_value_order_from_an_established_account_scores_zero(): void
    {
        $result = $this->module->score(['total' => 20.0, 'clientAccountAgeMinutes' => 60 * 24 * 365]);

        $this->assertSame(0.0, $result['score']);
        $this->assertFalse($result['hold']);
        $this->assertSame([], $result['reasons']);
    }

    public function test_high_value_order_alone_does_not_cross_the_hold_threshold(): void
    {
        $result = $this->module->score(['total' => 600.0, 'clientAccountAgeMinutes' => 60 * 24 * 365]);

        $this->assertSame(40.0, $result['score']);
        $this->assertFalse($result['hold']);
        $this->assertNotEmpty($result['reasons']);
    }

    public function test_brand_new_account_alone_does_not_cross_the_hold_threshold(): void
    {
        $result = $this->module->score(['total' => 20.0, 'clientAccountAgeMinutes' => 5]);

        $this->assertSame(35.0, $result['score']);
        $this->assertFalse($result['hold']);
    }

    public function test_high_value_order_from_a_brand_new_account_holds(): void
    {
        $result = $this->module->score(['total' => 600.0, 'clientAccountAgeMinutes' => 5]);

        $this->assertSame(90.0, $result['score']);
        $this->assertTrue($result['hold']);
        $this->assertCount(3, $result['reasons']);
    }

    public function test_score_never_exceeds_100_regardless_of_order_size(): void
    {
        $result = $this->module->score(['total' => 999999.0, 'clientAccountAgeMinutes' => 0]);

        $this->assertLessThanOrEqual(100.0, $result['score']);
        $this->assertTrue($result['hold']);
    }

    public function test_missing_account_age_is_treated_as_unknown_not_new(): void
    {
        $result = $this->module->score(['total' => 20.0]);

        $this->assertSame(0.0, $result['score']);
        $this->assertFalse($result['hold']);
    }
}
