<?php

namespace App\Http\Controllers;

use App\Models\StreamProfile;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class CastStreamController extends Controller
{
    public function live(Request $request, string $username, string $password, string|int $streamId, ?string $format = null): Response
    {
        return $this->playlist($request, 'live', $username, $password, $streamId, $format ?? 'm3u8');
    }

    public function movie(Request $request, string $username, string $password, string|int $streamId, ?string $format = null): Response
    {
        return $this->playlist($request, 'movie', $username, $password, $streamId, $format ?? 'm3u8');
    }

    public function series(Request $request, string $username, string $password, string|int $streamId, ?string $format = null): Response
    {
        return $this->playlist($request, 'series', $username, $password, $streamId, $format ?? 'm3u8');
    }

    public function segment(Request $request): Response
    {
        $source = $request->query('source');

        if (! is_string($source) || $source === '') {
            return response('Missing source', 422);
        }

        if (! $this->isAllowedSource($source)) {
            return response('Invalid source', 422);
        }

        $upstreamResponse = Http::timeout(30)
            ->withHeaders($this->forwardHeaders($request))
            ->get($source);

        if (! $upstreamResponse->successful()) {
            return response($upstreamResponse->body(), $upstreamResponse->status(), [
                'Content-Type' => $upstreamResponse->header('Content-Type', 'text/plain'),
            ]);
        }

        $contentType = $upstreamResponse->header('Content-Type', 'application/octet-stream');
        $body = $upstreamResponse->body();
        $isPlaylist = $this->isPlaylistResponse($source, $contentType, $body);

        if ($isPlaylist) {
            $body = $this->rewritePlaylist($body, $source);
            $contentType = 'application/vnd.apple.mpegurl';
        }

        $headers = [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Access-Control-Allow-Origin' => '*',
        ];

        if (! $isPlaylist) {
            foreach (['Content-Length', 'Content-Range', 'Accept-Ranges'] as $header) {
                $value = $upstreamResponse->header($header);
                if (is_string($value) && $value !== '') {
                    $headers[$header] = $value;
                }
            }
        }

        return response($body, $upstreamResponse->status(), $headers);
    }

    protected function playlist(Request $request, string $type, string $username, string $password, string|int $streamId, string $format): Response
    {
        $bootstrapRequest = Request::create(
            uri: match ($type) {
                'live' => "/live/{$username}/{$password}/{$streamId}.{$format}?proxy=true",
                'movie' => "/movie/{$username}/{$password}/{$streamId}.{$format}?proxy=true",
                'series' => "/series/{$username}/{$password}/{$streamId}.{$format}?proxy=true",
            },
            method: 'GET',
            cookies: $request->cookies->all(),
            server: [
                'HTTP_HOST' => $request->getHost(),
                'HTTPS' => $request->isSecure() ? 'on' : 'off',
                'REMOTE_ADDR' => $request->ip(),
                'QUERY_STRING' => http_build_query($this->castQueryOverrides($type)),
            ],
        );

        $bootstrapRequest->query->add($this->castQueryOverrides($type));
        $bootstrapResponse = app()->handle($bootstrapRequest);

        if (! $bootstrapResponse instanceof RedirectResponse) {
            Log::warning('Cast stream bootstrap failed to return redirect', [
                'type' => $type,
                'stream_id' => $streamId,
                'status' => method_exists($bootstrapResponse, 'getStatusCode') ? $bootstrapResponse->getStatusCode() : null,
            ]);

            return response('Stream bootstrap failed', 422);
        }

        $resolvedUrl = $bootstrapResponse->getTargetUrl();

        if (! $this->looksLikeHlsUrl($resolvedUrl)) {
            Log::warning('Cast stream bootstrap resolved to non-HLS url', [
                'type' => $type,
                'stream_id' => $streamId,
                'resolved_url' => $resolvedUrl,
            ]);

            return response('Cast stream requires an HLS-compatible profile', 422);
        }

        $playlistResponse = Http::timeout(30)
            ->withHeaders($this->forwardHeaders($request))
            ->get($resolvedUrl);

        if (! $playlistResponse->successful()) {
            return response($playlistResponse->body(), $playlistResponse->status(), [
                'Content-Type' => $playlistResponse->header('Content-Type', 'text/plain'),
            ]);
        }

        $playlistBody = $playlistResponse->body();
        $playlistContentType = $playlistResponse->header('Content-Type', 'application/octet-stream');

        if (! $this->isPlaylistResponse($resolvedUrl, $playlistContentType, $playlistBody)) {
            Log::warning('Cast stream playlist response was not valid HLS', [
                'type' => $type,
                'stream_id' => $streamId,
                'resolved_url' => $resolvedUrl,
                'content_type' => $playlistContentType,
            ]);

            return response('Cast stream did not resolve to a valid HLS playlist', 422);
        }

        $playlist = $this->rewritePlaylist($playlistBody, $resolvedUrl);

        return response($playlist, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    protected function castQueryOverrides(string $type): array
    {
        $settings = app(GeneralSettings::class);

        // Use cast-specific profile, falling back to in-app player profile
        $profileId = match ($type) {
            'movie', 'series' => $settings->default_cast_vod_stream_profile_id
                ?? $settings->default_vod_stream_profile_id
                ?? null,
            default => $settings->default_cast_stream_profile_id
                ?? $settings->default_stream_profile_id
                ?? null,
        };

        $profile = $profileId ? StreamProfile::find($profileId) : null;

        // Only use profiles that are HLS-compatible — Chromecast cannot play MPEGTS or raw streams
        if ($profile && ! in_array(strtolower((string) $profile->format), ['hls', 'm3u8'], true)) {
            $profile = null;
        }

        if (! $profile) {
            return [];
        }

        return [
            'proxy' => 'true',
            'profile_id' => $profile->id,
        ];
    }

    protected function rewritePlaylist(string $playlist, string $baseUrl): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $playlist) ?: [];

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if (str_starts_with($trimmed, '#')) {
                $lines[$index] = $this->rewriteTaggedUris($line, $baseUrl);

                continue;
            }

            $absoluteUrl = $this->resolveUrl($baseUrl, $trimmed);
            $lines[$index] = $absoluteUrl ? route('cast.stream.segment', ['source' => $absoluteUrl]) : $line;
        }

        return implode("\n", $lines);
    }

    protected function rewriteTaggedUris(string $line, string $baseUrl): string
    {
        return preg_replace_callback('/URI="([^"]+)"/', function (array $matches) use ($baseUrl) {
            $absoluteUrl = $this->resolveUrl($baseUrl, $matches[1]);

            if (! $absoluteUrl) {
                return $matches[0];
            }

            return 'URI="'.route('cast.stream.segment', ['source' => $absoluteUrl]).'"';
        }, $line) ?? $line;
    }

    protected function resolveUrl(string $baseUrl, string $candidate): ?string
    {
        $candidate = trim($candidate);

        if ($candidate === '') {
            return null;
        }

        if (str_starts_with($candidate, 'http://') || str_starts_with($candidate, 'https://')) {
            return $candidate;
        }

        $base = parse_url($baseUrl);
        if (! is_array($base) || empty($base['scheme']) || empty($base['host'])) {
            return null;
        }

        $origin = $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');

        if (str_starts_with($candidate, '//')) {
            return $base['scheme'].':'.$candidate;
        }

        if (str_starts_with($candidate, '/')) {
            return $origin.$candidate;
        }

        $basePath = $base['path'] ?? '/';
        $directory = preg_replace('#/[^/]*$#', '/', $basePath) ?: '/';

        return $origin.$this->normalizePath($directory.ltrim($candidate, '/'));
    }

    protected function normalizePath(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    protected function isAllowedSource(string $source): bool
    {
        if (! filter_var($source, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($source);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return in_array($scheme, ['http', 'https'], true);
    }

    protected function looksLikeHlsUrl(string $url): bool
    {
        return preg_match('/\.m3u8($|\?)/i', $url) === 1
            || str_contains(strtolower($url), '/playlist.m3u8')
            || str_contains(strtolower($url), '/hls/');
    }

    protected function isPlaylistResponse(string $source, string $contentType, string $body): bool
    {
        if (str_contains(strtolower($contentType), 'mpegurl')) {
            return true;
        }

        if (preg_match('/\.m3u8($|\?)/i', $source)) {
            return true;
        }

        return str_starts_with(ltrim($body), '#EXTM3U');
    }

    protected function forwardHeaders(Request $request): array
    {
        $headers = [];
        $range = $request->header('Range');

        if (is_string($range) && $range !== '') {
            $headers['Range'] = $range;
        }

        return $headers;
    }
}
