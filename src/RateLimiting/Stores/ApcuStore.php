<?php

declare(strict_types=1);

namespace App\RateLimiting\Stores;

use App\RateLimiting\Contracts\RateLimitStore;
use App\RateLimiting\WindowState;

/**
 * APCu shared memory. The realistic in-memory choice under PHP-FPM:
 *   - Survives across requests (it's the SAPI's shared segment, not request memory).
 *   - Shared across all workers ON THE SAME HOST -> one consistent limit per host.
 *   - Native TTL handles eviction, so memory growth is bounded automatically.
 *
 * Still breaks across hosts (each box has its own segment) -> see Redis migration.
 *
 * Note on atomicity: read-modify-write here is not atomic. Two concurrent
 * requests for the same key can interleave and slightly over-admit. Acceptable
 * for a capacity guardrail; the Redis backend closes it with an atomic Lua script.
 */
final class ApcuStore implements RateLimitStore
{
    public function __construct()
    {
        if (! \function_exists('apcu_fetch') || ! \ini_get('apc.enabled')) {
            throw new \RuntimeException('APCu is not enabled. Install ext-apcu or use ArrayStore.');
        }
    }

    public function read(string $key): ?WindowState
    {
        $row = \apcu_fetch($key, $ok);

        if (! $ok || ! \is_array($row)) {
            return null;
        }

        return new WindowState($row[0], $row[1], $row[2]);
    }

    public function write(string $key, WindowState $state, int $ttl): void
    {
        // TTL = 2 windows so the previous-window counter stays readable, then auto-evicts.
        \apcu_store($key, [$state->windowStart, $state->count, $state->prevCount], $ttl);
    }

    public function gc(int $olderThanSeconds): int
    {
        return 0; // APCu evicts on TTL.
    }
}
