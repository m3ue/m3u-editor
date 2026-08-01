<?php

use App\Http\Middleware\AutoLoginMiddleware;
use App\Http\Middleware\DispatcharrAuthMiddleware;
use App\Http\Middleware\ProxyRateLimitMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware
            ->use([
                AutoLoginMiddleware::class,
            ])
            ->alias([
                'dispatcharr.auth' => DispatcharrAuthMiddleware::class,
                'proxy.throttle' => ProxyRateLimitMiddleware::class,
            ])
            ->redirectGuestsTo('login')
            ->trustProxies(at: ['*'])
            ->preventRequestForgery(except: [
                'webhook/test',
                'channel',
                'channel/*',
                'group',
                'group/*',
                'playlist/*',
                'player_api.php',
                'get.php',
            ])
            ->throttleWithRedis();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // The Xtream Codes API endpoints (player_api.php, get.php) are consumed
        // by TV/IPTV clients that parse every response as JSON regardless of
        // the request's Accept header. Force JSON rendering for any uncaught
        // exception on these routes instead of Laravel's default HTML error
        // page, which those clients can't parse.
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            // Force JSON for the Xtream endpoints regardless of Accept header
            // (clients parse every response as JSON). For every other route,
            // fall through to Laravel's default expectsJson() check so API
            // routes that send Accept: application/json still get JSON
            // 401/422 from auth/validation middleware.
            return in_array($request->route()?->getName(), [
                'xtream.api.player',
                'xtream.api.get',
            ], true) || $request->expectsJson();
        });
    })->create();
