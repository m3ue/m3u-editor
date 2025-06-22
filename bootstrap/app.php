<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware
            ->redirectGuestsTo('login')
            ->trustProxies(at: ['*'])
            ->validateCsrfTokens(except: [
                'webhook/test',
            ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\App\Exceptions\MaxRetriesReachedException $e, \Illuminate\Http\Request $request) {
            // Log the error fully
            \Illuminate\Support\Facades\Log::error('Stream failed with MaxRetriesReachedException: ' . $e->getMessage(), [
                'exception' => $e,
                'url' => $request->fullUrl(),
            ]);

            // Return a simple text response to avoid the HTML error page, which causes "headers already sent"
            return new \Illuminate\Http\Response('Stream failed after multiple retries.', 503, ['Content-Type' => 'text/plain']);
        });
    })->create();
