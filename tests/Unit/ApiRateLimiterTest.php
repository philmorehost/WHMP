<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Api\ApiRateLimiter;
use CodeVault\Database\Migrator;
use CodeVault\Tests\Support\DatabaseTestCase;

/**
 * The rate limiter behind the 429 the API documentation has always
 * promised. Real MariaDB, real upsert: the interesting behaviours are all
 * at the storage boundary — the same caller counting up, a DIFFERENT
 * caller unaffected, and a window rolling over to a fresh allowance.
 */
final class ApiRateLimiterTest extends DatabaseTestCase
{
    private ApiRateLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations'))->run();
        $this->limiter = new ApiRateLimiter($this->db);
    }

    public function test_requests_under_the_limit_are_allowed(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertFalse($this->limiter->hit('ip:10.0.0.1', 3, 60));
        }
    }

    public function test_the_request_after_the_limit_is_rejected(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->limiter->hit('ip:10.0.0.2', 3, 60);
        }

        $this->assertTrue($this->limiter->hit('ip:10.0.0.2', 3, 60), 'the (N+1)th request must be blocked');
        $this->assertTrue($this->limiter->hit('ip:10.0.0.2', 3, 60), 'staying blocked until the window rolls');
    }

    public function test_one_caller_being_throttled_does_not_touch_another(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->hit('ip:10.0.0.3', 3, 60);
        }

        $this->assertTrue($this->limiter->hit('ip:10.0.0.3', 3, 60));
        $this->assertFalse($this->limiter->hit('ip:10.0.0.4', 3, 60), 'a fresh identifier starts unblocked');
    }

    public function test_a_new_window_starts_with_a_fresh_allowance(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->limiter->hit('ip:10.0.0.5', 3, 60);
        }
        $this->assertTrue($this->limiter->hit('ip:10.0.0.5', 3, 60));

        // Roll the clock: a hit in the PREVIOUS window is a different row.
        $this->db->statement('UPDATE api_rate_limits SET window_start = window_start - 120');

        $this->assertFalse($this->limiter->hit('ip:10.0.0.5', 3, 60), 'the next window must start clean');
    }

    public function test_retry_after_always_lands_inside_the_window(): void
    {
        $retry = ApiRateLimiter::retryAfter(60);

        $this->assertGreaterThanOrEqual(1, $retry);
        $this->assertLessThanOrEqual(60, $retry);
    }
}
