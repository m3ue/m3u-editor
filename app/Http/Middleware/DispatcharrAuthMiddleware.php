<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DispatcharrAuthMiddleware
{
    /**
     * Validate a Dispatcharr-compatible Bearer token (HMAC-signed).
     *
     * Token format: base64(json({playlist_id, playlist_type, user_id, exp})).signature
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        if (! $bearer) {
            return response()->json(['detail' => 'Authentication credentials were not provided.'], 401);
        }

        $payload = static::verifyToken($bearer);
        if (! $payload) {
            return response()->json(['detail' => 'Given token not valid for any token type.'], 401);
        }

        if (($payload['exp'] ?? 0) < time()) {
            return response()->json(['detail' => 'Token has expired.'], 401);
        }

        $request->attributes->set('dispatcharr_payload', $payload);

        return $next($request);
    }

    /**
     * Create a signed token with the given payload and TTL.
     *
     * @param  array{playlist_id: int, playlist_type: string, user_id: int}  $payload
     */
    public static function createToken(array $payload, int $ttlSeconds): string
    {
        $payload['exp'] = time() + $ttlSeconds;
        $encoded = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', $encoded, config('app.key'));

        return $encoded.'.'.$signature;
    }

    /**
     * Verify a signed token and return its payload, or null if invalid.
     */
    public static function verifyToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;

        $expected = hash_hmac('sha256', $encoded, config('app.key'));
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $json = base64_decode($encoded, true);
        if ($json === false) {
            return null;
        }

        $payload = json_decode($json, true);
        if (! is_array($payload)) {
            return null;
        }

        return $payload;
    }

    /**
     * Create a URL-safe, HMAC-signed stream token embedding channel and playlist info.
     *
     * Token format: base64url(json({c,p,t})).hmac_prefix
     */
    public static function createStreamToken(int $channelId, int $playlistId, string $playlistType): string
    {
        $data = json_encode(['c' => $channelId, 'p' => $playlistId, 't' => $playlistType]);
        $encoded = rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        $signature = substr(hash_hmac('sha256', $encoded, config('app.key')), 0, 16);

        return "{$encoded}.{$signature}";
    }

    /**
     * Verify a stream token and return its payload, or null if invalid.
     *
     * @return array{c: int, p: int, t: string}|null
     */
    public static function verifyStreamToken(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;
        $expected = substr(hash_hmac('sha256', $encoded, config('app.key')), 0, 16);

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $padded = strtr($encoded, '-_', '+/');
        $padded = str_pad($padded, strlen($padded) + (4 - strlen($padded) % 4) % 4, '=');
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            return null;
        }

        $payload = json_decode($decoded, true);
        if (! is_array($payload) || ! isset($payload['c'], $payload['p'], $payload['t'])) {
            return null;
        }

        return $payload;
    }
}
