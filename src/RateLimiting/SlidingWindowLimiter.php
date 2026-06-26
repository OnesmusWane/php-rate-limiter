<?php

declare(strict_types=1);

namespace App\RateLimiting;

use App\RateLimiting\Contracts\RateLimitStore;

/**
 * Sliding-window counter.
 *
 * Why this algorithm:
 *   - Fixed window is simpler but allows a 2x burst across the boundary
 *     (the incident we are guarding against).
 *   - Sliding-window LOG is exact but stores every timestamp -> unbounded
 *     memory per key, which fights the in-memory constraint.
 *   - Sliding-window COUNTER keeps O(1) memory per key (two counters) and
 *     smooths the boundary by weighting the previous window. Good enough for
 *     a capacity guardrail; the small approximation error is on the safe side
 *     when traffic is steady.
 *
 * Only allowed requests are counted. A throttled client does not extend its
 * own lockout by hammering — this avoids starvation.
 *
 * The clock is injectable ($now) so the algorithm is unit-testable without sleeps.
 */
final class SlidingWindowLimiter
{
    public function __construct(private readonly RateLimitStore $store)
    {
    }

    public function attempt(string $key, int $limit, int $window, ?int $now = null): RateLimitResult
    {
        $now ??= time();
        $currentStart = intdiv($now, $window) * $window;

        [$count, $prev] = $this->roll($this->store->read($key), $currentStart, $window);

        $elapsed = $now - $currentStart;
        $weight = ($window - $elapsed) / $window;
        $estimated = $prev * $weight + $count;

        if ($estimated >= $limit) {
            return new RateLimitResult(
                allowed: false,
                limit: $limit,
                remaining: 0,
                retryAfter: $this->retryAfter($prev, $count, $limit, $window, $elapsed),
            );
        }

        $this->store->write($key, new WindowState($currentStart, $count + 1, $prev), $window * 2);

        return new RateLimitResult(
            allowed: true,
            limit: $limit,
            remaining: (int) max(0, floor($limit - $estimated - 1)),
            retryAfter: 0,
        );
    }

    /**
     * Map stored state onto the current window: same window, the window just
     * before it (slide), or a gap of >= 2 windows (fully decayed).
     *
     * @return array{0:int,1:int} [count, prevCount]
     */
    private function roll(?WindowState $state, int $currentStart, int $window): array
    {
        if ($state === null) {
            return [0, 0];
        }

        if ($state->windowStart === $currentStart) {
            return [$state->count, $state->prevCount];
        }

        if ($state->windowStart === $currentStart - $window) {
            return [0, $state->count];
        }

        return [0, 0];
    }

    /**
     * Seconds until the estimate would drop below the limit, assuming no new
     * traffic. If the current window alone is already at the limit, decay of
     * the previous window cannot help — wait for the window to roll.
     */
    private function retryAfter(int $prev, int $count, int $limit, int $window, int $elapsed): int
    {
        if ($count >= $limit) {
            return max(1, $window - $elapsed);
        }

        if ($prev <= 0) {
            return 1;
        }

        $needed = $window - $elapsed - ($window * ($limit - $count) / $prev);

        return max(1, (int) ceil($needed));
    }
}
