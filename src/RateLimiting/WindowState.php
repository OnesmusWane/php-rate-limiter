<?php

declare(strict_types=1);

namespace App\RateLimiting;

/**
 * Immutable snapshot of a key's counters for the sliding-window algorithm.
 * Two counters are enough: the current fixed window and the previous one.
 */
final class WindowState
{
    public function __construct(
        public readonly int $windowStart,
        public readonly int $count,
        public readonly int $prevCount,
    ) {
    }
}
