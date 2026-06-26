<?php

declare(strict_types=1);

// Zero-dependency runner: `php run-tests.php`. No composer, no phpunit, no Laravel.

// Index every .php under this dir by class basename, then autoload by short name.
// Works whether files are nested (src/RateLimiting/...) or flat.
$index = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->getExtension() === 'php' && !str_contains($file->getPathname(), '/vendor/')) {
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

$pass = 0;
$fail = 0;

function check(string $name, callable $fn): void
{
    global $pass, $fail;
    try {
        $fn();
        echo "  PASS  {$name}\n";
        $GLOBALS['pass']++;
    } catch (Throwable $e) {
        echo "  FAIL  {$name} :: {$e->getMessage()}\n";
        $GLOBALS['fail']++;
    }
}

function expect(bool $cond, string $msg): void
{
    if (! $cond) {
        throw new RuntimeException($msg);
    }
}

$limiter = static fn (): SlidingWindowLimiter => new SlidingWindowLimiter(new ArrayStore(gcSampleRate: 0.0));

check('allows exactly up to the limit in a fresh window', function () use ($limiter) {
    $l = $limiter();
    $allowed = 0;
    for ($i = 0; $i < 150; $i++) {
        if ($l->attempt('org1:read', 100, 60, now: 1000)->allowed) {
            $allowed++;
        }
    }
    expect($allowed === 100, "expected 100 allowed, got {$allowed}");
});

check('returns 429 metadata when blocked', function () use ($limiter) {
    $l = $limiter();
    for ($i = 0; $i < 100; $i++) {
        $l->attempt('k', 100, 60, now: 1000);
    }
    $r = $l->attempt('k', 100, 60, now: 1000);
    expect($r->allowed === false, 'expected blocked');
    expect($r->remaining === 0, "expected remaining 0, got {$r->remaining}");
    expect($r->retryAfter === 20, "expected retryAfter 20, got {$r->retryAfter}");
});

check('previous window decays into the new one', function () use ($limiter) {
    $l = $limiter();
    for ($i = 0; $i < 100; $i++) {
        $l->attempt('k', 100, 60, now: 1000);
    }
    expect($l->attempt('k', 100, 60, now: 1021)->allowed === true, 'first into new window should pass');
    $blocked = false;
    for ($i = 0; $i < 5; $i++) {
        if (! $l->attempt('k', 100, 60, now: 1021)->allowed) {
            $blocked = true;
            break;
        }
    }
    expect($blocked, 'should block well before a fresh 100');
});

check('full reset after two idle windows', function () use ($limiter) {
    $l = $limiter();
    for ($i = 0; $i < 100; $i++) {
        $l->attempt('k', 100, 60, now: 1000);
    }
    $r = $l->attempt('k', 100, 60, now: 1130);
    expect($r->allowed === true, 'should be allowed after idle');
    expect($r->remaining === 99, "expected remaining 99, got {$r->remaining}");
});

check('reads and writes are independent buckets', function () use ($limiter) {
    $l = $limiter();
    for ($i = 0; $i < 20; $i++) {
        $l->attempt('org1:write', 20, 60, now: 1000);
    }
    expect($l->attempt('org1:write', 20, 60, now: 1000)->allowed === false, 'writes should be exhausted');
    expect($l->attempt('org1:read', 100, 60, now: 1000)->allowed === true, 'reads should be untouched');
});

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
