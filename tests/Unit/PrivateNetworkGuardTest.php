<?php

use App\Support\PrivateNetworkGuard;

/**
 * Tests for the SSRF guard that every cache download, webhook, and
 * logo-proxy HTTP fetch passes through.
 *
 * `ipIsPrivate()` is a pure function over PHP's
 * FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE flags - the
 * dataset pins which IP ranges we treat as private/reserved on this
 * PHP build so a future PHP-flag behaviour change is caught here.
 *
 * `assertUrlSafe()` covers the URL surface: scheme allowlist, missing
 * host, userinfo tricks, IPv6 bracketed literals, decimal/hex/octal
 * encodings (only when the guard itself claims to handle them - any
 * unsupported form is documented as a finding, not asserted wrong),
 * and the `$allowPrivate` bypass for trusted deployments.
 */
it('flags RFC1918, loopback, link-local, 0.0.0.0, and IPv6 private ranges as private/reserved', function (string $ip) {
    expect(PrivateNetworkGuard::ipIsPrivate($ip))->toBeTrue();
})->with([
    // Loopback (IPv4 + IPv6)
    'ipv4 loopback' => '127.0.0.1',
    'ipv4 loopback alt' => '127.255.255.254',

    // RFC1918 private
    '10/8 prefix' => '10.0.0.1',
    '10/8 mid' => '10.255.255.254',
    '172.16/12 low' => '172.16.0.1',
    '172.16/12 mid' => '172.20.10.5',
    '172.16/12 high' => '172.31.255.254',
    '192.168/16' => '192.168.0.1',
    '192.168/16 high' => '192.168.255.254',

    // Link-local / cloud metadata
    'ipv4 link-local' => '169.254.1.1',
    'AWS metadata endpoint' => '169.254.169.254',

    // 0.0.0.0
    'all zeros' => '0.0.0.0',

    // IPv6
    'ipv6 loopback' => '::1',
    'ipv6 unique-local fc00::/7' => 'fc00::1',
    'ipv6 unique-local fd::/8' => 'fd12:3456:789a::1',
    'ipv6 link-local fe80::/10' => 'fe80::1',
    'ipv6 link-local alt' => 'febf:ffff::1',

    // IPv4-mapped IPv6 - PHP's filter strips the ::ffff: prefix and
    // re-checks the embedded v4 against the same private/reserved flags.
    'ipv4-mapped 127.0.0.1' => '::ffff:127.0.0.1',
    'ipv4-mapped 10.0.0.1' => '::ffff:10.0.0.1',

    // Multicast - NOT covered by PHP's FILTER_FLAG_NO_PRIV_RANGE |
    // FILTER_FLAG_NO_RES_RANGE; the guard adds an explicit check.
    'ipv4 multicast low (224.0.0.0/4)' => '224.0.0.1',
    'ipv4 multicast mid (224.0.0.0/4)' => '232.1.2.3',
    'ipv4 multicast high (224.0.0.0/4)' => '239.255.255.255',
    'ipv6 multicast ff00::/8 all-nodes' => 'ff02::1',
    'ipv6 multicast ff00::/8 site-local' => 'ff05::1:3',
    'ipv6 multicast ff00::/8 generic' => 'ffee:1234::1',
]);

it('flags 172.15.x and 172.32.x as public (edge cases just outside RFC1918)', function (string $ip) {
    // 172.16.0.0/12 is the private slice; 172.15.x.x and 172.32.x.x are
    // public and the guard MUST NOT reject them - this prevents a
    // misconfiguration that blocks legitimate operators on nearby IPs.
    expect(PrivateNetworkGuard::ipIsPrivate($ip))->toBeFalse();
})->with([
    'just below 172.16' => '172.15.0.1',
    'just above 172.31' => '172.32.0.1',
]);

it('treats well-known public addresses as public', function (string $ip) {
    expect(PrivateNetworkGuard::ipIsPrivate($ip))->toBeFalse();
})->with([
    'Google DNS IPv4' => '8.8.8.8',
    'Cloudflare DNS IPv4' => '1.1.1.1',
    'Google DNS IPv6' => '2001:4860:4860::8888',
    'Cloudflare DNS IPv6' => '2606:4700:4700::1111',
]);

it('intentionally allows CGNAT 100.64.0.0/10 (provider / Tailscale reachability)', function (string $ip) {
    // 100.64.0.0/10 (RFC 6598) is intentionally NOT in the deny-list:
    // operators routinely reach providers that live behind ISP-side
    // CGNAT, and Tailscale exit nodes commonly sit in that range. A
    // false-positive rejection here would block legitimate downloads
    // with no real SSRF-protection benefit. The dataset exercises the
    // four corners (RFC 6598 reserves the full /10).
    expect(PrivateNetworkGuard::ipIsPrivate($ip))->toBeFalse();
})->with([
    'CGNAT low edge 100.64.0.0' => '100.64.0.0',
    'CGNAT mid 100.100.100.100' => '100.100.100.100',
    'CGNAT mid ISP-style 100.64.0.1' => '100.64.0.1',
    'CGNAT high edge 100.127.255.255' => '100.127.255.255',
]);

it('does NOT extend the multicast check past 239.x (224.0.0.0/4 boundary)', function () {
    // The multicast check targets 224.0.0.0/4 (first byte 0xE0-0xEF,
    // range 224-239). 223.x is just below that boundary and is NOT
    // classified as private by the guard's multicast branch - PHP's
    // own NO_PRIV/NO_RES_RANGE flags also leave 223.x as public. This
    // pins the byte-range boundary so a future "extend the check to
    // all 0xE0+" change would be caught.
    expect(PrivateNetworkGuard::ipIsPrivate('223.255.255.255'))->toBeFalse();
});

it('assertUrlSafe accepts a normal public http URL with an IP-literal host', function () {
    // The guard resolves IP-literal hosts inline (no DNS), so this is
    // safe to assert without network access.
    $resolved = PrivateNetworkGuard::assertUrlSafe('http://8.8.8.8/movie.mp4');

    expect($resolved)->toBe('8.8.8.8');
});

it('assertUrlSafe accepts a public https URL with an IP-literal host', function () {
    $resolved = PrivateNetworkGuard::assertUrlSafe('https://8.8.8.8/movie.mp4');

    expect($resolved)->toBe('8.8.8.8');
});

it('assertUrlSafe throws on non-http(s) schemes', function (string $url) {
    PrivateNetworkGuard::assertUrlSafe($url);
})->with([
    'file:// scheme' => 'file:///etc/passwd',
    'ftp:// scheme' => 'ftp://8.8.8.8/whatever',
    'gopher:// scheme' => 'gopher://8.8.8.8',
])->throws(InvalidArgumentException::class);

it('assertUrlSafe throws when the URL is missing a scheme or host', function (string $url) {
    PrivateNetworkGuard::assertUrlSafe($url);
})->with([
    'no scheme at all' => '8.8.8.8/movie.mp4',
    'no host (only path)' => 'http:///movie.mp4',
    'empty string' => '',
])->throws(InvalidArgumentException::class);

it('assertUrlSafe rejects a literal private IPv4 host', function (string $url) {
    PrivateNetworkGuard::assertUrlSafe($url);
})->with([
    'loopback' => 'http://127.0.0.1/movie.mp4',
    'loopback https' => 'https://127.0.0.1:8080/x',
    '10/8' => 'http://10.0.0.1/movie.mp4',
    '172.16/12' => 'http://172.20.10.5/movie.mp4',
    '192.168/16' => 'http://192.168.0.1/x',
    'link-local metadata' => 'http://169.254.169.254/latest/meta-data/',
    '0.0.0.0' => 'http://0.0.0.0/x',
])->throws(InvalidArgumentException::class);

it('assertUrlSafe rejects a literal IPv6 host wrapped in brackets', function () {
    // The guard strips the surrounding brackets before validating - if
    // it did NOT, the validated text would be "[::1]" (not a valid IP)
    // and the guard would throw "Could not resolve URL host" instead of
    // the private-IP rejection. This case pins the bracket-strip
    // behaviour as the SSRF-rejecting path.
    expect(fn () => PrivateNetworkGuard::assertUrlSafe('http://[::1]/x'))
        ->toThrow(InvalidArgumentException::class, 'resolves to a private/reserved IP');

    expect(fn () => PrivateNetworkGuard::assertUrlSafe('https://[fc00::1]/x'))
        ->toThrow(InvalidArgumentException::class, 'resolves to a private/reserved IP');
});

it('assertUrlSafe rejects the userinfo @-trick pointing the host at a private IP', function () {
    // http://public.com@127.0.0.1/ - PHP's parse_url reports "host" as
    // the trailing authority (127.0.0.1), "user" as "public.com". Many
    // naive guards decode the user-part as a hostname and treat the URL
    // as public. The correct behaviour is to honour parse_url's host
    // (127.0.0.1) and reject.
    expect(fn () => PrivateNetworkGuard::assertUrlSafe('http://public.com@127.0.0.1/x'))
        ->toThrow(InvalidArgumentException::class, 'resolves to a private/reserved IP');

    expect(fn () => PrivateNetworkGuard::assertUrlSafe('https://attacker.example@10.0.0.1/admin'))
        ->toThrow(InvalidArgumentException::class, 'resolves to a private/reserved IP');
});

it('assertUrlSafe accepts a public URL when $allowPrivate = true even with a literal private IP', function () {
    // Documented bypass for trusted deployments (test env, isolated lab
    // setups). The guard MUST still resolve and return the IP, just
    // without throwing.
    $resolved = PrivateNetworkGuard::assertUrlSafe('http://127.0.0.1/x', allowPrivate: true);
    expect($resolved)->toBe('127.0.0.1');

    $resolved = PrivateNetworkGuard::assertUrlSafe('http://10.0.0.1/x', allowPrivate: true);
    expect($resolved)->toBe('10.0.0.1');
});

it('assertUrlSafe still enforces the scheme allowlist when $allowPrivate = true', function () {
    // The bypass is ONLY for the IP/host check, not for scheme validation -
    // a non-http(s) URL with allowPrivate=true must still be rejected.
    // Use URLs that have a parseable host so they exercise the scheme
    // check (file:///etc/passwd has no host and would be rejected by the
    // missing-host branch instead).
    expect(fn () => PrivateNetworkGuard::assertUrlSafe('ftp://127.0.0.1/x', allowPrivate: true))
        ->toThrow(InvalidArgumentException::class, 'not allowed');

    expect(fn () => PrivateNetworkGuard::assertUrlSafe('gopher://127.0.0.1/x', allowPrivate: true))
        ->toThrow(InvalidArgumentException::class, 'not allowed');
});

it('assertUrlSafe rejects numeric IPv4 encodings that PHP does not treat as FILTER_VALIDATE_IP', function () {
    // The guard routes every host through filter_var(FILTER_VALIDATE_IP)
    // before applying the IP rules. Decimal-encoded (2130706433),
    // hex-encoded (0x7f000001), and octal-encoded (0177.0.0.1) forms
    // are NOT validated as IP by the filter, so the guard's literal-IP
    // branch never sees them - they fall through to gethostbyname(),
    // which then fails to resolve them and produces a "Could not resolve"
    // rejection. Pin the actual behaviour here as the source-of-truth.
    expect(fn () => PrivateNetworkGuard::assertUrlSafe('http://2130706433/x'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => PrivateNetworkGuard::assertUrlSafe('http://0x7f000001/x'))
        ->toThrow(InvalidArgumentException::class);
});

it('assertUrlSafe throws on a missing scheme even when the host would be safe', function () {
    // Defensive: a scheme-less URL (parsed as path-relative host) must
    // be rejected with the missing-scheme message, not the DNS failure.
    expect(fn () => PrivateNetworkGuard::assertUrlSafe('//8.8.8.8/x'))
        ->toThrow(InvalidArgumentException::class, 'missing scheme or host');
});
