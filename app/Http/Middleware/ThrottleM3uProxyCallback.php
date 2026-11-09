<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Select at request time so route:cache never freezes the opt-in setting.
 * Inherit the throttle priority and delegate to the application's throttle alias
 * (including its Redis implementation), preserving the legacy signature/key.
 */
class ThrottleM3uProxyCallback extends ThrottleRequests
{
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        $throttle = app(app('router')->getMiddleware()['throttle']);

        if (config('proxy.isolated_callback_rate_limits') === true) {
            return $throttle->handle($request, $next, 'm3u-proxy-callback-'.$maxAttempts);
        }

        return $throttle->handle($request, $next, 120, 1);
    }
}
