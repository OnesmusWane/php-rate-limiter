<?php

declare(strict_types=1);

namespace Tests\RateLimiting;

use App\RateLimiting\SlidingWindowLimiter;
use App\RateLimiting\Stores\ArrayStore;
use PHPUnit\Framework\TestCase;

/**
 * The algorithm is tested in isolation with a fixed clock, so these are fast,
 * deterministic, and require no Laravel container or sleeps.
 */
final class SlidingWindowLimiterTest extends TestCase
{
    private function limiter(): SlidingWindowLimiter
    {
        // gcSampleRate 0 keeps GC out of the way during assertions.
        return new SlidingWindowLimiter(new ArrayStore(gcSampleRate: 0.0));
    }

    public function test_allows_exactly_up_to_the_limit_in_a_fresh_window(): void
    {
        $limiter = $this->limiter();
        $allowed = 0;

        for ($i = 0; $i < 150; $i++) {
            if ($limiter->attempt('org1:read', limit: 100, window: 60, now: 1000)->allowed) {
                $allowed++;
            }
        }

        self::assertSame(100, $allowed);
    }

    public function test_returns_429_metadata_when_blocked(): void
    {
        $limiter = $this->limiter();

        for ($i = 0; $i < 100; $i++) {
            $limiter->attempt('k', 100, 60, now: 1000); // window start 960, elapsed 40
        }

        $result = $limiter->attempt('k', 100, 60, now: 1000);

        self::assertFalse($result->allowed);
        self::assertSame(0, $result->remaining);
        self::assertSame(20, $result->retryAfter); // window(60) - elapsed(40)
    }

    public function test_previous_window_decays_into_the_new_one(): void
    {
        $limiter = $this->limiter();

        for ($i = 0; $i < 100; $i++) {
            $limiter->attempt('k', 100, 60, now: 1000); // fully fill window A
        }

        // 1s into window B: weight ~0.983, estimate ~98.3 -> a tiny burst is allowed,
        // not a full fresh 100. That is the boundary smoothing working.
        $first = $limiter->attempt('k', 100, 60, now: 1021);
        self::assertTrue($first->allowed);

        $blocked = false;
        for ($i = 0; $i < 5; $i++) {
            if (! $limiter->attempt('k', 100, 60, now: 1021)->allowed) {
                $blocked = true;
                break;
            }
        }
        self::assertTrue($blocked, 'sliding window should block well before a fresh 100');
    }

    public function test_full_reset_after_two_idle_windows(): void
    {
        $limiter = $this->limiter();

        for ($i = 0; $i < 100; $i++) {
            $limiter->attempt('k', 100, 60, now: 1000);
        }

        $result = $limiter->attempt('k', 100, 60, now: 1000 + 130);

        self::assertTrue($result->allowed);
        self::assertSame(99, $result->remaining);
    }

    public function test_reads_and_writes_are_independent_buckets(): void
    {
        $limiter = $this->limiter();

        for ($i = 0; $i < 20; $i++) {
            $limiter->attempt('org1:write', 20, 60, now: 1000);
        }

        // writes exhausted...
        self::assertFalse($limiter->attempt('org1:write', 20, 60, now: 1000)->allowed);
        // ...but reads are untouched.
        self::assertTrue($limiter->attempt('org1:read', 100, 60, now: 1000)->allowed);
    }
}
