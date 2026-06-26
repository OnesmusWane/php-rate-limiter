<?php

declare(strict_types=1);

namespace App\RateLimiting\Contracts;

use App\RateLimiting\WindowState;

/**
 * Storage seam for counter state. The limiter algorithm depends on this
 * abstraction only — swapping in-memory for Redis is a binding change,
 * not an algorithm change.
 */
interface RateLimitStore
{
    public function read(string $key): ?WindowState;

    public function write(string $key, WindowState $state, int $ttl): void;

    /**
     * Evict keys not touched within $olderThanSeconds. Returns the count removed.
     * No-op for stores with native TTL (e.g. APCu, Redis).
     */
    public function gc(int $olderThanSeconds): int;
}
