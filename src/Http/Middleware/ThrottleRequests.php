<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\RateLimiting\SlidingWindowLimiter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sits in front of API handlers. Resolves the org and method-class, asks the
 * limiter, and either short-circuits with 429 or annotates the response with
 * rate-limit headers.
 *
 * Register as a route-middleware alias (see README) and attach to the API
 * group — one place, not per route.
 */
final class ThrottleRequests
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly SlidingWindowLimiter $limiter,
        private readonly array $config,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $org = $this->resolveOrg($request);

        if ($org === null) {
            return $this->config['unidentified'] === 'block'
                ? $this->reject('unidentified', 0, $this->config['window'])
                : $next($request);
        }

        $class  = $this->config['method_classes'][$request->getMethod()] ?? 'write';
        $tier   = $this->config['org_tiers'][$org] ?? $this->config['default_tier'];
        $limit  = $this->config['tiers'][$tier][$class];
        $window = $this->config['window'];

        $result = $this->limiter->attempt("{$org}:{$class}", $limit, $window);

        if (! $result->allowed) {
            return $this->reject($class, $result->limit, $window, $result->retryAfter);
        }

        $response = $next($request);
        $response->headers->add([
            'X-RateLimit-Limit'     => (string) $result->limit,
            'X-RateLimit-Remaining' => (string) $result->remaining,
        ]);

        return $response;
    }

    private function resolveOrg(Request $request): ?string
    {
        // Prefer the authenticated principal; fall back to an explicit header
        // for the PoC. Never trust a client-supplied id in production without auth.
        $user = $request->user();

        if ($user !== null && isset($user->org_id)) {
            return (string) $user->org_id;
        }

        return $request->headers->get('X-Org-Id');
    }

    private function reject(string $scope, int $limit, int $window, int $retryAfter = 1): Response
    {
        return new \Illuminate\Http\JsonResponse(
            [
                'message'        => 'Rate limit exceeded.',
                'scope'          => $scope,   // which limit was hit: read | write | unidentified
                'limit'          => $limit,
                'window_seconds' => $window,
                'retry_after'    => $retryAfter,
            ],
            Response::HTTP_TOO_MANY_REQUESTS,
            [
                'Retry-After'           => (string) $retryAfter,
                'X-RateLimit-Limit'     => (string) $limit,
                'X-RateLimit-Remaining' => '0',
            ],
        );
    }
}
