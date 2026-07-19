<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Fraud\DeepSeekFraudTriageModule;
use CodeVault\Tests\Fixtures\FakeAiProvider;
use PHPUnit\Framework\TestCase;

final class DeepSeekFraudTriageModuleTest extends TestCase
{
    private FakeAiProvider $ai;
    private DeepSeekFraudTriageModule $module;

    protected function setUp(): void
    {
        $this->ai = new FakeAiProvider();
        $this->module = new DeepSeekFraudTriageModule($this->ai);
    }

    public function test_ai_provider_failure_scores_zero_and_fails_open(): void
    {
        $this->ai->respondWith(false, null, 'AI provider unavailable.');

        $result = $this->module->score(['total' => 10000.0]);

        $this->assertSame(0.0, $result['score']);
        $this->assertFalse($result['hold']);
    }

    public function test_parses_a_well_formed_json_response(): void
    {
        $this->ai->respondWith(true, json_encode(['score' => 72, 'reasons' => ['Order composition looks unusual.']]));

        $result = $this->module->score(['total' => 300.0, 'clientName' => 'Jane Doe', 'clientEmail' => 'jane@example.com']);

        $this->assertSame(72.0, $result['score']);
        $this->assertTrue($result['hold']);
        $this->assertSame(['Order composition looks unusual.'], $result['reasons']);
    }

    public function test_redacts_client_name_before_sending(): void
    {
        $this->ai->respondWith(true, json_encode(['score' => 0, 'reasons' => []]));

        $this->module->score(['total' => 10.0, 'clientName' => 'contact jane@example.com', 'clientEmail' => 'jane@example.com']);

        $sent = $this->ai->lastCall()['user'];
        $this->assertStringNotContainsString('jane@example.com', $sent);
    }

    public function test_score_below_50_does_not_hold(): void
    {
        $this->ai->respondWith(true, json_encode(['score' => 20, 'reasons' => []]));

        $result = $this->module->score(['total' => 10.0]);

        $this->assertFalse($result['hold']);
    }

    public function test_malformed_json_content_fails_open(): void
    {
        $this->ai->respondWith(true, 'not json');

        $result = $this->module->score(['total' => 10000.0]);

        $this->assertSame(0.0, $result['score']);
        $this->assertFalse($result['hold']);
    }

    public function test_score_is_clamped_to_0_100_range(): void
    {
        $this->ai->respondWith(true, json_encode(['score' => 500, 'reasons' => []]));

        $result = $this->module->score(['total' => 10.0]);

        $this->assertSame(100.0, $result['score']);
    }
}
