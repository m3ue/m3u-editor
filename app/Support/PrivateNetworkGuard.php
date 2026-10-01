<?php

namespace App\Support;

use Closure;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

/**
 * Centralised guard for outbound HTTP destinations. Used wherever the
 * app fetches an arbitrary operator-controlled URL - cache downloads,
 * webhook callbacks, logo proxies, managed-setup calls.
 *
 * Rules:
 *  - Scheme MUST be http or https.
 *  - Host MUST resolve to a non-private, non-reserved, non-loopback,
 *    non-link-local IP. This blocks SSRF to internal services
 *    (localhost admin panels, link-local metadata endpoints, RFC1918
 *    internal subnets).
 *  - Every redirect hop is held to the same rules - checking only the
 *    first URL lets a public host 302 the request onto 127.0.0.1.
 *  - Optional allow_private toggle for trusted deployments (test env,
 *    isolated lab setups) - opt-in only, never the default.
 *
 * Usage:
 *   PrivateNetworkGuard::assertUrlSafe($url);
 *   // throws on disallowed scheme or destination; returns true on safe.
 *
 *   PrivateNetworkGuard::get($url, fn () => Http::timeout(10));
 *   // GET with every hop checked and IP-pinned.
 */
class PrivateNetworkGuard
{
    /**
     * Determine whether an IP address is private, loopback, link-local,
     * multicast, or otherwise reserved.
     *
     * PHP's `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`
     * already covers RFC1918, loopback, link-local, the IPv6 ULA / link-
     * local blocks, and the reserved IPv4 ranges. It does NOT cover the
     * multicast blocks - those are checked explicitly below.
     *
     * CGNAT (100.64.0.0/10, RFC 6598) is intentionally NOT treated as
     * private here: operators routinely reach providers that live behind
     * ISP-side CGNAT (and Tailscale exit nodes commonly sit in that
     * range), and a false-positive rejection would block legitimate
     * downloads with no real SSRF-protection benefit. If a future threat
     * model requires CGNAT rejection, gate it behind an explicit
     * `$denyCgnat` parameter rather than changing this default.
     *
     * Multicast blocks ARE treated as unsafe: SSRF attempts can pivot to
     * multicast listeners on co-located infrastructure, and there is no
     * legitimate "operator reaches us at a multicast IP" use case here.
     *  - IPv4: 224.0.0.0/4 (first byte 0xE0-0xEF)
     *  - IPv6: ff00::/8 (first byte 0xFF)
     */
    public static function ipIsPrivate(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            // Unparseable (and yet FILTER_VALIDATE_IP said it was fine) -
            // fail closed.
            return true;
        }

        if (strlen($packed) === 4) {
            // IPv4 multicast 224.0.0.0/4.
            $first = ord($packed[0]);
            if ($first >= 0xE0 && $first <= 0xEF) {
                return true;
            }
        } else {
            // IPv6 multicast ff00::/8.
            if (ord($packed[0]) === 0xFF) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate a URL for outbound HTTP use. Throws InvalidArgumentException
     * when the scheme is not http(s) or the host resolves to a
     * private/reserved address.
     *
     * `@` and other hostname tricks (numeric IPv4 in URL form, IPv6 with
     * zone, etc.) are rejected by parse_url + gethostbyname. Returns the
     * resolved IP as a string so callers can log the destination.
     */
    public static function assertUrlSafe(string $url, bool $allowPrivate = false): string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException("URL is missing scheme or host: {$url}");
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException("URL scheme '{$scheme}' is not allowed (only http and https).");
        }

        $host = (string) $parts['host'];

        // Strip IPv6 brackets if present.
        $bareHost = trim($host, '[]');

        // If the host is a literal IP, validate it directly without DNS.
        if (filter_var($bareHost, FILTER_VALIDATE_IP) !== false) {
            if (! $allowPrivate && self::ipIsPrivate($bareHost)) {
                throw new InvalidArgumentException("URL host {$host} resolves to a private/reserved IP.");
            }

            return $bareHost;
        }

        // Hostname - resolve to its IP and validate.
        $ip = gethostbyname($bareHost);
        if ($ip === $bareHost) {
            // gethostbyname failed (returned the hostname back unchanged).
            throw new InvalidArgumentException("Could not resolve URL host: {$host}");
        }

        if (! $allowPrivate && self::ipIsPrivate($ip)) {
            throw new InvalidArgumentException("URL host {$host} resolves to a private/reserved IP ({$ip}).");
        }

        return $ip;
    }

    /**
     * GET a URL with the HTTP client's own redirect-following disabled,
     * walking each Location manually so every hop passes assertUrlSafe()
     * and connects to the exact IP that was checked (CURLOPT_RESOLVE).
     * Pinning closes the DNS rebinding gap between the check and the
     * connect.
     *
     * @param  Closure(): PendingRequest  $makeRequest  Builds the base request (headers, timeout, stream mode) for each hop.
     *
     * @throws InvalidArgumentException When any hop is not a safe http(s) destination.
     * @throws RuntimeException When the chain is longer than $maxRedirects.
     */
    public static function get(string $url, Closure $makeRequest, int $maxRedirects = 5): Response
    {
        $current = $url;

        for ($i = 0; $i <= $maxRedirects; $i++) {
            $resolvedIp = self::assertUrlSafe($current);
            $parts = parse_url($current);
            $host = (string) $parts['host'];
            $port = (int) ($parts['port'] ?? (strtolower((string) $parts['scheme']) === 'https' ? 443 : 80));

            $response = $makeRequest()
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [
                        CURLOPT_RESOLVE => [trim($host, '[]').":{$port}:{$resolvedIp}"],
                    ],
                ])
                ->get($current);

            $location = $response->header('Location');
            if (! $response->redirect() || ! $location) {
                return $response;
            }

            $current = (string) UriResolver::resolve(Utils::uriFor($current), Utils::uriFor($location));
        }

        throw new RuntimeException("Exceeded {$maxRedirects} redirects while fetching {$url}.");
    }

    /**
     * Guzzle options that keep the HTTP client's own redirect handling
     * (POST downgraded to GET on 302/303, Authorization dropped on
     * cross-origin hops) but refuse to follow a hop to a non-http(s) or
     * private destination. For requests get() can't make (POST, custom
     * bodies). Later hops are re-checked but not IP-pinned.
     *
     * @return array{allow_redirects: array{on_redirect: Closure}}
     */
    public static function redirectGuardOptions(): array
    {
        return [
            'allow_redirects' => [
                'on_redirect' => static function (RequestInterface $request, ResponseInterface $response, UriInterface $uri): void {
                    self::assertUrlSafe((string) $uri);
                },
            ],
        ];
    }
}
