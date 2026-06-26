<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Rate limiting
|--------------------------------------------------------------------------
| Everything tunable lives here. Limits are per (org, method-class) and can
| be changed without touching code. In a real deployment these can be hydrated
| from env, a control-plane table, or a feature-flag service at boot.
*/

return [

    // Sliding window length in seconds.
    'window' => 60,

    // Tier used when an org has no explicit mapping below.
    'default_tier' => 'standard',

    // Per-tier limits, split by method class. Writes are stricter than reads
    // because they are the expensive, capacity-consuming operations.
    'tiers' => [
        'standard' => ['read' => 100, 'write' => 20],
        'premium'  => ['read' => 500, 'write' => 100],
    ],

    // Explicit org -> tier overrides. Anything not listed gets default_tier.
    'org_tiers' => [
        // 'org_42' => 'premium',
    ],

    // HTTP method -> class. Anything unlisted is treated as 'write' (fail safe).
    'method_classes' => [
        'GET'     => 'read',
        'HEAD'    => 'read',
        'OPTIONS' => 'read',
        'POST'    => 'write',
        'PUT'     => 'write',
        'PATCH'   => 'write',
        'DELETE'  => 'write',
    ],

    // Where requests with no resolvable org go: 'allow' (let auth layer reject)
    // or 'block'. We default to allow so the throttle never masks a 401.
    'unidentified' => 'allow',
];
