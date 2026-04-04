<?php

use App\Exceptions\MaxRetriesReachedException;
use App\Http\Middleware\AutoLoginMiddleware;
use App\Http\Middleware\ProxyRateLimitMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
                'proxy.throttle' => ProxyRateLimitMiddleware::class,
            ])
            ->redirectGuestsTo('login')
            ->trustProxies(at: ['*'])
            ->validateCsrfTokens(except: [
                'webhook/test',
                'channel',
                'channel/*',
                'group',
                'group/*',
                'player_api.php',
                'get.php',
            ])
            ->throttleWithRedis();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (MaxRetriesReachedException $e, Request $request) {
            Log::error('Stream failed after max retries: '.$e->getMessage(), [
                'exception' => $e,
                'url' => $request->fullUrl(),
            ]);

            if (! headers_sent()) {
                http_response_code(503);
                header('Content-Type: text/plain; charset=UTF-8');
                header('Retry-After: 30');
                echo 'Stream failed after multiple retries. Please try again later.';
            }

            // Prevent Laravel's default HTML error page (causes "headers already sent" error
            // when we're mid-stream response).
            exit;
        });
    })->create();
