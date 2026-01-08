<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AllPlaylistsExhaustedException extends Exception
{
    /**
     * Report the exception.
     *
     * @return bool|null
     */
    public function report()
    {
        // You can add custom logging here if needed,
        // but the controller will likely log it as well.
        return false; // Returning false prevents default logging if you handle it elsewhere.
    }

    /**
     * Render the exception into an HTTP response.
     *
     * @param  Request  $request
     * @return Response|JsonResponse
     */
    public function render($request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 429);
        }

        // For web requests, you could return a view here,
        // but we'll handle it in the controller for more flexibility.
        // Or, you can use abort() which Laravel's handler will turn into a nice error page.
        return response($this->getMessage(), 429);
    }
}
