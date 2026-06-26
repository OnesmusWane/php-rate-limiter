<?php

declare(strict_types=1);

namespace App\RateLimiting\Stores;

use App\RateLimiting\Contracts\RateLimitStore;
use App\RateLimiting\WindowState;

/**
 * Process-local associative array.
 *
 * Correct ONLY when the process is resident across requests and there is a
 * single worker holding this instance (Octane/Swoole/RoadRunner with one
 * worker, or a long-running daemon). See README "Failure modes":
 *   - Under PHP-FPM the array is empty on every request -> never throttles.
 *   - With N resident workers each holds its own array -> effective limit ~ N*limit.
 *
 * Memory is bounded by probabilistic GC: a sweep runs on a small fraction of
 * writes so silent keys don't leak forever. A bounded-LRU or TTL store is the
 * real fix (APCu/Redis give it for free).
 */
final class ArrayStore implements RateLimitStore
{
    /** @var array<string, array{0:int,1:int,2:int,3:int}> key => [windowStart, count, prevCount, lastTouched] */
    private array $data = [];

    public function __construct(private readonly float $gcSampleRate = 0.01)
    {
    }

    public function read(string $key): ?WindowState
    {
        $row = $this->data[$key] ?? null;

        if ($row === null) {
            return null;
        }

        return new WindowState($row[0], $row[1], $row[2]);
    }

    public function write(string $key, WindowState $state, int $ttl): void
    {
        $this->data[$key] = [$state->windowStart, $state->count, $state->prevCount, time()];

        if (mt_rand() / mt_getrandmax() < $this->gcSampleRate) {
            $this->gc($ttl);
        }
    }

    public function gc(int $olderThanSeconds): int
    {
        $cutoff = time() - $olderThanSeconds;
        $removed = 0;

        foreach ($this->data as $key => $row) {
            if ($row[3] < $cutoff) {
                unset($this->data[$key]);
                $removed++;
            }
        }

        return $removed;
    }
}
