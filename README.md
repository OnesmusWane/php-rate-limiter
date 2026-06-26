# Rate-limiting middleware (PoC)

In-memory, per-org and per-method-class rate limiting that sits in front of API
handlers and rejects abusive traffic before it reaches application logic.

Built after one client's sync job ate 40% of API capacity for three hours. The
goal is a **capacity guardrail**: no single org can starve the others, and the
behaviour is configurable without a deploy.

> **PoC scope.** Correct algorithm, honest trade-offs, clear migration path.
> Not production-hardened. The "Failure modes" section is the important read —
> the in-memory constraint is load-bearing and changes what this can promise.

---

## TL;DR

- **Algorithm:** sliding-window counter. O(1) memory per key, no boundary burst.
- **Granularity:** limits are keyed by `{org}:{read|write}`. Writes are stricter
  than reads because they cost more.
- **Config:** everything lives in `config/rate-limiting.php`. Change limits/tiers
  without touching code.
- **On reject:** `429`, a JSON body naming which limit was hit, and a `Retry-After`
  header computed from the actual window state.
- **Storage:** behind a `RateLimitStore` interface. Swap in-memory → Redis is a
  binding change, not an algorithm change.

---

## How to wire it (Laravel)

```php
// bootstrap/providers.php  (or config/app.php providers array)
App\Providers\RateLimitingServiceProvider::class,
```

```php
// bootstrap/app.php (Laravel 11+) — attach to the API group, one place.
->withMiddleware(function (Middleware $middleware) {
    $middleware->api(append: [
        App\Http\Middleware\ThrottleRequests::class,
    ]);
})
```

That's it — no per-route changes. The middleware resolves the org from the
authenticated user (`$request->user()->org_id`), falling back to an `X-Org-Id`
header for the PoC.

---

## How it works

A fixed-window counter is the obvious approach but allows a **2x burst across the
boundary**: 100 requests at 11:00:59 and another 100 at 11:01:00 both pass. That
is exactly the spike we're guarding against.

A sliding-window *log* (store every timestamp) is exact but uses unbounded memory
per key — bad under an in-memory constraint.

So this uses a sliding-window **counter**: keep the current fixed window's count
and the previous window's count, then weight the previous one by how much of it
is still "in view":

```
estimate = prev_count * (window - elapsed)/window + current_count
```

Two integers per key, no boundary burst, error is small and on the safe side for
steady traffic. Only **allowed** requests are counted, so a throttled client can't
extend its own lockout by hammering.

`Retry-After` is solved from the window state — the seconds until `estimate` would
fall back under the limit assuming no new traffic — not a flat guess. If the
current window alone is already over the limit, decay can't help and we return the
time until the window rolls.

Verified against five scenarios (fresh-window cap, mid-window block + retry math,
boundary decay, full reset after idle, read/write isolation) — see
`tests/SlidingWindowLimiterTest.php`.

---

## Configuration

`config/rate-limiting.php`:

```php
'window' => 60,
'default_tier' => 'standard',
'tiers' => [
    'standard' => ['read' => 100, 'write' => 20],
    'premium'  => ['read' => 500, 'write' => 100],
],
'org_tiers' => [ 'org_42' => 'premium' ],   // anything unlisted -> default_tier
```

Method → class mapping is also config-driven; anything unlisted is treated as
`write` (fail safe — unknown verbs get the stricter limit).

---

## Error response

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 18
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 0
```

```json
{
  "message": "Rate limit exceeded.",
  "scope": "write",
  "limit": 20,
  "window_seconds": 60,
  "retry_after": 18
}
```

Allowed responses carry `X-RateLimit-Limit` and `X-RateLimit-Remaining` so clients
can self-pace before they ever hit a 429.

---

## Storage backends

The limiter depends only on `RateLimitStore`. Three implementations, increasing
in correctness:

| Backend       | Survives request? | Shared across workers? | Across hosts? | Eviction        | Use when |
|---------------|-------------------|------------------------|---------------|-----------------|----------|
| `ArrayStore`  | Only on a resident runtime (Octane/Swoole) | No — per worker | No | Probabilistic GC | Local/dev, single-worker |
| `ApcuStore`   | Yes (PHP-FPM too) | Yes (per host)         | No            | Native TTL      | Single-host prod |
| `RedisStore`* | Yes               | Yes                    | **Yes**       | Native TTL      | Multi-host prod |

\* Not built here (in-memory constraint). The interface is the seam — see migration.

---

## Failure modes (read this)

These are real and the in-memory constraint causes most of them.

1. **PHP-FPM resets memory per request.** `ArrayStore` is empty at the start of
   every request, so it would *never throttle* under stock PHP-FPM. It is correct
   only on a resident runtime (Octane/Swoole/RoadRunner) or a long-running daemon.
   → On PHP-FPM, use `ApcuStore` (shared segment survives requests). The provider
   auto-selects APCu when available.

2. **Multiple workers diverge.** Each `ArrayStore` worker holds its own counters,
   so with N workers the effective limit is roughly **N × limit**. APCu fixes this
   *within a host* (shared segment); across hosts it does not.

3. **Horizontal scaling breaks the global limit.** With M app servers each running
   APCu, a client gets ~**M × limit** total. In-memory cannot enforce a cluster-wide
   limit — that's the wall, and the reason the real answer is Redis.

4. **Memory growth.** Every active key holds a small record. `ArrayStore` bounds it
   with probabilistic GC (sweep on a fraction of writes); silent keys still linger
   until swept. APCu/Redis bound it natively via TTL. A pathological key space
   (e.g. unauthenticated traffic keyed by something unbounded) would still grow —
   key only on identified, bounded principals.

5. **Read-modify-write race.** Check-then-write isn't atomic. Concurrent requests
   for the same key can interleave and slightly over-admit. Tolerable for a
   guardrail; the Redis backend closes it with an atomic Lua script.

6. **Process restart = amnesia.** A deploy or crash wipes all counters; a client
   gets a fresh budget immediately after. Acceptable for a guardrail, not for
   billing/quota accounting.

7. **`Retry-After` is an estimate.** It assumes no new traffic during the wait.
   Conservative and standards-compliant, not a precise SLA.

---

## Migration path to Redis (when infra is available)

The only thing that changes is one binding:

```php
// RateLimitingServiceProvider::register()
$this->app->singleton(RateLimitStore::class, fn () => new RedisStore(/* connection */));
```

`RedisStore` implements the same interface, runs the read/decide/write as a single
atomic Lua script (closing failure mode #5), and uses Redis TTL for eviction (#4).
That single change resolves the multi-worker (#2), multi-host (#3), and restart
(#6) problems because state moves out of process memory into a shared store. The
algorithm, middleware, config, and tests are untouched.

---

## Tests

```bash
composer require --dev phpunit/phpunit
vendor/bin/phpunit tests/
```

Tests run against `ArrayStore` with an injected clock — deterministic, no sleeps,
no container boot.

---

## Trade-offs & what I'd do with more time

- **Sliding-window counter over token bucket.** Token bucket models burst
  allowance more naturally; sliding-window maps more directly to the "N req/min"
  the team and clients reason about. Either would work — I optimised for
  operator/client clarity.
- **Read/write classes, not per-route limits.** Matches the requirement and keeps
  the key space small. The key scheme (`{org}:{class}`) extends to `{org}:{route}`
  with no algorithm change when a specific endpoint needs its own ceiling.
- **No warning tier / soft limits.** Real systems usually want a "you're at 80%"
  signal and per-tier burst overrides before a hard 429. Out of PoC scope.
- **Org resolution is thin.** Production should resolve org from the auth token
  only, never a client header, and decide policy for unauthenticated traffic
  (IP-keyed limiting with a separate, tighter budget).
