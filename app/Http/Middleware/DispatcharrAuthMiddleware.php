<?php

namespace App\Http\Middleware;

use Closure;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Symfony\Component\HttpFoundation\Response;

class DispatcharrAuthMiddleware
{
    /**
     * Stream token TTL: 24 hours.
     */
    private const STREAM_TOKEN_TTL = 86400;

    /**
     * Registered JWT claim names that are managed by the builder directly.
     */
    private const REGISTERED_CLAIMS = ['iss', 'sub', 'aud', 'exp', 'nbf', 'iat', 'jti'];

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
     * Issue a signed HS256 JWT with the given custom claims and TTL.
     *
     * Registered claims (iss, sub, aud, exp, nbf, iat, jti) in the payload
     * are ignored — exp and iat are set via the builder automatically.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function createToken(array $payload, int $ttlSeconds): string
    {
        $config = static::jwtConfig();
        $now = new DateTimeImmutable;

        $builder = $config->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify("+{$ttlSeconds} seconds"));

        foreach ($payload as $key => $value) {
            if (! in_array($key, self::REGISTERED_CLAIMS, true)) {
                $builder = $builder->withClaim($key, $value);
            }
        }

        return $builder->getToken($config->signer(), $config->signingKey())->toString();
    }

    /**
     * Verify a JWT signature and return its claims as an array, or null if invalid.
     *
     * Does not validate expiry — callers are responsible for checking $payload['exp'].
     *
     * @return array<string, mixed>|null
     */
    public static function verifyToken(string $token): ?array
    {
        try {
            $config = static::jwtConfig();
            $parsed = $config->parser()->parse($token);

            $config->validator()->assert(
                $parsed,
                new SignedWith($config->signer(), $config->signingKey())
            );

            $claims = $parsed->claims()->all();

            // Normalise DateTimeImmutable timestamps to Unix integers
            foreach ($claims as $key => $value) {
                if ($value instanceof DateTimeImmutable) {
                    $claims[$key] = $value->getTimestamp();
                }
            }

            return $claims;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Issue a URL-safe HS256 JWT stream token embedding channel and playlist info.
     *
     * Tokens expire after 24 hours, addressing the permanent-validity gap in the
     * previous hand-rolled HMAC scheme.
     */
    public static function createStreamToken(int $channelId, int $playlistId, string $playlistType): string
    {
        $config = static::jwtConfig();
        $now = new DateTimeImmutable;

        return $config->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify('+'.self::STREAM_TOKEN_TTL.' seconds'))
            ->withClaim('c', $channelId)
            ->withClaim('p', $playlistId)
            ->withClaim('t', $playlistType)
            ->getToken($config->signer(), $config->signingKey())
            ->toString();
    }

    /**
     * Verify a stream token signature and expiry, returning its payload or null.
     *
     * @return array{c: int, p: int, t: string}|null
     */
    public static function verifyStreamToken(string $token): ?array
    {
        $payload = static::verifyToken($token);

        if (! $payload || ! isset($payload['c'], $payload['p'], $payload['t'])) {
            return null;
        }

        if (($payload['exp'] ?? 0) < time()) {
            return null;
        }

        return [
            'c' => (int) $payload['c'],
            'p' => (int) $payload['p'],
            't' => (string) $payload['t'],
        ];
    }

    /**
     * Build an HS256 JWT Configuration using the application key.
     */
    private static function jwtConfig(): Configuration
    {
        $key = config('app.key');
        $signingKey = str_starts_with($key, 'base64:')
            ? InMemory::base64Encoded(substr($key, 7))
            : InMemory::plainText($key);

        return Configuration::forSymmetricSigner(new Sha256, $signingKey);
    }
}
