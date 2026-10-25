<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A cache-backed limiter for GET /ready that fails open when the cache itself
 * is the failing dependency.
 *
 * A normal `throttle:60,1` route middleware would make a Redis outage return a
 * 500 from the limiter before the readiness check can report which dependency
 * failed. The readiness endpoint is also covered by nginx's loose edge limit,
 * so failing open here still leaves a rate limit in front of it.
 */
class ReadyRateLimit
{
    private const MAX_ATTEMPTS = 60;

    private const DECAY_SECONDS = 60;

    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = 'ready:'.$request->ip();

        try {
            if ($this->limiter->tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                return response()->json(
                    ['error' => 'rate_limited'],
                    429,
                    ['Retry-After' => $this->limiter->availableIn($key)],
                );
            }

            $this->limiter->hit($key, self::DECAY_SECONDS);
        } catch (Throwable) {
            // Fail open: readiness must remain observable while Redis is down.
        }

        return $next($request);
    }
}
