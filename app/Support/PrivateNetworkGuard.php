<?php

namespace App\Support;

use InvalidArgumentException;

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
 *  - Optional allow_private toggle for trusted deployments (test env,
 *    isolated lab setups) - opt-in only, never the default.
 *
 * Usage:
 *   PrivateNetworkGuard::assertUrlSafe($url);
 *   // throws on disallowed scheme or destination; returns true on safe.
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
}
