<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared fail-closed auth gate for every route the m3u-proxy service calls back into
 * the editor on: failover-resolver, webhooks, broadcast/callback, and dvr/callback.
 *
 * Accepts the same shared token the editor sends when calling the proxy, via either
 * the X-API-Token header or an api_token query parameter (the editor embeds the
 * latter in the callback URLs it hands to the proxy — see M3uProxyService).
 *
 * Unlike the previous per-controller check, an unconfigured token now rejects every
 * callback rather than accepting all of them. Set `proxy.allow_unauthenticated_callbacks`
 * only for a trusted deployment where the proxy isn't reachable from outside.
 */
class VerifyM3uProxyCallback
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = config('proxy.m3u_proxy_token');

        if (empty($configuredToken)) {
            if (config('proxy.allow_unauthenticated_callbacks')) {
                Log::warning('m3u-proxy callback: no proxy token configured — accepting under explicit local-development bypass', [
                    'path' => $request->path(),
                ]);

                return $next($request);
            }

            Log::warning('m3u-proxy callback: rejected — no M3U_PROXY_TOKEN configured and local bypass is disabled', [
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $providedToken = $request->header('X-API-Token') ?? $request->query('api_token') ?? '';

        if (! hash_equals((string) $configuredToken, (string) $providedToken)) {
            Log::warning('m3u-proxy callback: unauthorized request', [
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
