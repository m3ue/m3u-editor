<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
                \App\Http\Middleware\AutoLoginMiddleware::class,
            ])
            ->alias([
                'proxy.throttle' => \App\Http\Middleware\ProxyRateLimitMiddleware::class,
            ])
            ->redirectGuestsTo('login')
            ->trustProxies(at: ['*'])
            ->validateCsrfTokens(except: [
                'webhook/test',
                'channel',
                'channel/*',
            ])
            ->throttleWithRedis();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // TODO: Review global exception handling for MaxRetriesReachedException after StreamController refactor.
        // $exceptions->render(function (\App\Exceptions\MaxRetriesReachedException $e, \Illuminate\Http\Request $request) {
        //     // Log the error fully
        //     \Illuminate\Support\Facades\Log::error('Stream failed with MaxRetriesReachedException (minimal handler - temporarily disabled): ' . $e->getMessage(), [
        //         'exception' => $e,
        //         'url' => $request->fullUrl(),
        //     ]);

        //     if (!headers_sent()) {
        //         // Attempt to send a minimal plain text error response
        //         // This might still fail if StreamedResponse partially sent headers, but it's the best attempt.
        //         http_response_code(503);
        //         header('Content-Type: text/plain; charset=UTF-8');
        //         echo 'Stream failed after multiple retries.';
        //     }
        //     // Crucially, exit here to prevent Laravel's default error handler
        //     // from trying to render an HTML page, which causes the "headers already sent" fatal error.
        //     exit;
        // });
    })->create();
