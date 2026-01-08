<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DashboardMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check() && ! config('app.redirect_guest_to_login', false)) {
            // Check if the user is trying the login page
            if ($request->path() === trim(config('app.login_path', 'login'), '/')) {
                return $next($request);
            }

            return redirect()->to('/not-found');
        }

        return $next($request);
    }
}
