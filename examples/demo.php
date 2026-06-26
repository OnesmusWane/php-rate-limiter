<?php

declare(strict_types=1);

/*
 * Runnable integration demo:  php examples/demo.php
 *
 * No Laravel required. It wires the real limiter to the real config file and
 * drives it with simulated requests, so you can see per-client and per-endpoint
 * limiting (and Retry-After) without standing up an HTTP server.
 *
 * The org/method -> limit resolution below mirrors ThrottleRequests::handle();
 * the limiter and config are the same artifacts used in production.
 */

// --- minimal autoloader (basename index over src/) ---------------------------
$index = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__ . '/../src', FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $file) {
    if ($file->getExtension() === 'php') {
        $index[$file->getBasename('.php')] = $file->getPathname();
    }
}
spl_autoload_register(static function (string $class) use ($index): void {
    $short = substr($class, (int) strrpos($class, '\\') + 1);
    if (isset($index[$short])) {
        require $index[$short];
    }
});

use App\RateLimiting\SlidingWindowLimiter;
use App\RateLimiting\Stores\ArrayStore;

$config = require __DIR__ . '/../config/rate-limiting.php';
$config['org_tiers']['org_premium'] = 'premium'; // promote one org for the demo

$limiter = new SlidingWindowLimiter(new ArrayStore(gcSampleRate: 0.0));
$now = 1_000_000; // fixed clock so the demo is deterministic

/** Mirrors the middleware's resolution step. */
$resolve = static function (string $org, string $method) use ($config): array {
    $class = $config['method_classes'][$method] ?? 'write';
    $tier  = $config['org_tiers'][$org] ?? $config['default_tier'];

    return [$class, $config['tiers'][$tier][$class], $config['window']];
};

$send = static function (string $org, string $method, int $count) use ($limiter, $resolve, $now): void {
    [$class, $limit, $window] = $resolve($org, $method);
    $allowed = 0;
    $firstReject = null;

    for ($i = 1; $i <= $count; $i++) {
        $r = $limiter->attempt("{$org}:{$class}", $limit, $window, now: $now);
        if ($r->allowed) {
            $allowed++;
        } elseif ($firstReject === null) {
            $firstReject = ['at' => $i, 'retry' => $r->retryAfter];
        }
    }

    printf(
        "  %-12s %-6s (%s, limit %3d/min):  %3d allowed, %3d rejected",
        $org, $method, $class, $limit, $allowed, $count - $allowed
    );
    if ($firstReject !== null) {
        printf("  -> first 429 at #%d, Retry-After %ds", $firstReject['at'], $firstReject['retry']);
    }
    echo "\n";
};

echo "\nScenario 1 — per-client: same endpoint, different tiers\n";
$send('org_standard', 'GET', 130);   // standard reads cap at 100
$send('org_premium', 'GET', 130);    // premium reads cap at 500 -> all pass

echo "\nScenario 2 — per-endpoint: writes are stricter than reads (standard tier)\n";
$send('org_writer', 'GET', 60);      // 60 reads, well under 100
$send('org_writer', 'POST', 60);     // writes cap at 20

echo "\nScenario 3 — buckets are independent: exhausting writes leaves reads intact\n";
$send('org_mixed', 'DELETE', 25);    // writes exhausted at 20
$send('org_mixed', 'GET', 25);       // reads untouched -> all pass

echo "\nThe noisy client is contained to its own bucket; other clients and other\n";
echo "method classes are unaffected. That is the capacity guardrail.\n\n";
