<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AutoLoginMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request):Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('auth.auto_login') && ! auth()->check()) {
            $user = User::where('email', config('auth.auto_login_email'))->first();
            if ($user) {
                auth()->login($user);
            }
        }

        return $next($request);
    }
}
