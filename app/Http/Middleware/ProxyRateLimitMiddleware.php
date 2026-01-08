<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ProxyRateLimitMiddleware
{
    /**
     * Handle an incoming request with early termination rate limiting.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'proxy:'.$request->path();

        // Check if rate limit is exceeded
        if (RateLimiter::tooManyAttempts($key, 20)) {
            $retryAfter = RateLimiter::availableIn($key);

            // Delay response to mitigate brute-force
            sleep(min($retryAfter, 5));

            // Return a minimal response immediately without further processing
            return response()->json([
                'message' => 'Too many requests. Slow down there cowboy!',
            ], 429, [
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => 20,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        // Increment the rate limiter counter
        RateLimiter::hit($key, 60 * 2); // decay in seconds

        $remaining = 20 - RateLimiter::attempts($key);

        // Continue to the next middleware
        $response = $next($request);

        // Add rate limit headers to the response
        return $response->withHeaders([
            'X-RateLimit-Limit' => 20,
            'X-RateLimit-Remaining' => max(0, $remaining),
        ]);
    }
}
