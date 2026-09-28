<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\DvrAccessScope;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the Dispatcharr-style DVR endpoints with a Sanctum API token and
 * resolves the caller's DvrAccessScope onto the `dvr_scope` request attribute.
 *
 * The token is accepted as (first match wins):
 *   - `X-API-Key: <token>` or `Authorization: ApiKey <token>`
 *   - `Authorization: Bearer <token>`
 *   - `?token=<token>`, for players that cannot set headers on media requests
 *
 * The token is scoped to every DVR setting its owner has and must carry the
 * ability named by the middleware parameter (e.g. `dispatcharr.dvr:view`).
 */
class DispatcharrDvrAuthMiddleware
{
    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        $plainTextToken = $this->apiKeyFromRequest($request) ?? $request->bearerToken() ?? $request->query('token');

        if (! is_string($plainTextToken) || $plainTextToken === '') {
            return $this->unauthenticated('Authentication credentials were not provided.');
        }

        $accessToken = Sanctum::$personalAccessTokenModel::findToken($plainTextToken);

        if (! $accessToken || ! $accessToken->tokenable instanceof User) {
            return $this->unauthenticated('Invalid token.');
        }

        $expiration = config('sanctum.expiration');
        $isExpired = ($accessToken->expires_at && $accessToken->expires_at->isPast())
            || ($expiration && $accessToken->created_at->lte(now()->subMinutes($expiration)));

        if ($isExpired) {
            return $this->unauthenticated('Token has expired.');
        }

        if ($ability && ! $accessToken->can($ability)) {
            return $this->forbidden();
        }

        $accessToken->forceFill(['last_used_at' => now()])->save();

        $scope = DvrAccessScope::forUser($accessToken->tokenable->withAccessToken($accessToken));

        if (! $scope->granted()) {
            return $this->forbidden();
        }

        $request->attributes->set('dvr_scope', $scope);

        return $next($request);
    }

    private function apiKeyFromRequest(Request $request): ?string
    {
        $header = $request->header('X-API-Key');
        if (is_string($header) && $header !== '') {
            return $header;
        }

        $authorization = (string) $request->header('Authorization', '');
        if (str_starts_with(strtolower($authorization), 'apikey ')) {
            return trim(substr($authorization, 7)) ?: null;
        }

        return null;
    }

    private function unauthenticated(string $detail): Response
    {
        return response()->json(['detail' => $detail], 401);
    }

    private function forbidden(): Response
    {
        return response()->json(['detail' => 'You do not have permission to perform this action.'], 403);
    }
}
