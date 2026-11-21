<?php

namespace App\Http\Controllers;

use App\Services\M3uProxyService;
use App\Services\TimeshiftBufferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the shared per-channel rolling timeshift HLS window.
 *
 * Only the ~1 KB playlist passes through the editor (segment URLs rewritten
 * to absolute proxy URLs); every .ts segment is fetched client→proxy.
 * Playlist fetches re-touch the buffer TTL so the reaper keeps it alive
 * while anyone is watching.
 *
 * With EPG, the served playlist is trimmed so the window starts at the
 * current programme's start (rewind never crosses into the previous show).
 * Without EPG the full rolling window (up to max_window_seconds) is served.
 */
class TimeshiftStreamController extends Controller
{
    public function __construct(protected TimeshiftBufferService $timeshift) {}

    /**
     * GET /timeshift-live/signed/{uuid}/live.m3u8
     */
    public function signedPlaylist(Request $request, string $uuid): Response
    {
        $bufferId = $uuid;
        $this->timeshift->touch($bufferId);

        $playlistUrl = $this->proxyBroadcastPlaylistUrl($bufferId);
        $playlist = $this->fetchPlaylistWithStartupWait($playlistUrl);
        if ($playlist === null) {
            // Buffer still warming (FFmpeg hasn't written a playlist yet).
            // Serve a valid, empty live playlist so HLS clients poll for the
            // first segments instead of failing the cold .m3u8 open.
            return $this->emptyLivePlaylist();
        }

        $playlist = $this->trimToProgrammeStart($playlist, $bufferId);

        $segmentBase = $this->publicSegmentBase($bufferId);
        $playlist = preg_replace('/^(live\d+\.ts)\r?$/m', $segmentBase.'$1', $playlist);

        return response($playlist, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * GET /timeshift-live/signed/{uuid}/{segment}.ts
     *
     * Segments are normally absolute after the playlist rewrite; this route
     * exists for clients that re-resolve relative names against the playlist URL.
     */
    public function signedSegment(Request $request, string $uuid, string $segment): Response
    {
        if (! preg_match('/^live\d+\.ts$/', $segment)) {
            abort(404, 'Segment not found');
        }
        $this->timeshift->touch($uuid);

        $target = $this->publicSegmentBase($uuid).$segment;

        return redirect()->to($target, 302, ['Cache-Control' => 'public, max-age=60'], false);
    }

    /**
     * Drop segments that belong to the previous programme when EPG says the
     * current show started after the buffer did. Stream keeps running; only
     * the served window start moves. No EPG → no trim (rolling window).
     *
     * EXT-X-MEDIA-SEQUENCE is rewritten to match the first kept segment so
     * ExoPlayer's playlist refresh doesn't see a sequence/URI mismatch
     * (that mismatch caused "Source error" ~1s after start).
     */
    protected function trimToProgrammeStart(string $playlist, string $bufferId): string
    {
        $channelId = $this->timeshift->bufferChannelId($bufferId);
        $startedAt = $this->timeshift->bufferStartedAt($bufferId);
        if ($channelId === null || $startedAt === null) {
            return $playlist;
        }

        $programmeStart = $this->timeshift->currentProgrammeStart($channelId);
        if ($programmeStart === null || $programmeStart <= $startedAt) {
            return $playlist;
        }

        $segmentSeconds = max(1, (int) config('timeshift.segment_seconds', 2));
        $skip = (int) floor(($programmeStart - $startedAt) / $segmentSeconds);
        if ($skip <= 0) {
            return $playlist;
        }

        $lines = preg_split('/\r?\n/', $playlist);
        $out = [];
        $segmentsSeen = 0;
        $pendingExtinf = null;
        $firstKeptNumber = null;

        foreach ($lines as $line) {
            if (str_starts_with($line, '#EXT-X-MEDIA-SEQUENCE:')) {
                // Placeholder — rewritten after we know the first kept segment.
                $out[] = $line;

                continue;
            }
            if (str_starts_with($line, '#EXTINF')) {
                $pendingExtinf = $line;

                continue;
            }
            if (preg_match('/^live(\d+)\.ts$/', $line, $m)) {
                $segmentsSeen++;
                if ($segmentsSeen <= $skip) {
                    $pendingExtinf = null;

                    continue;
                }
                if ($firstKeptNumber === null) {
                    $firstKeptNumber = (int) $m[1];
                }
                if ($pendingExtinf !== null) {
                    $out[] = $pendingExtinf;
                    $pendingExtinf = null;
                }
                $out[] = $line;

                continue;
            }
            if ($pendingExtinf !== null) {
                $pendingExtinf = null;
            }
            $out[] = $line;
        }

        if ($firstKeptNumber === null) {
            // Trimmed everything — nothing playable; leave as-is (player will
            // retry / fall back rather than a sequence-mismatched empty window).
            return $playlist;
        }

        // Rewrite MEDIA-SEQUENCE to the first kept segment number (1-based liveNNNNNN).
        foreach ($out as $i => $line) {
            if (str_starts_with($line, '#EXT-X-MEDIA-SEQUENCE:')) {
                $out[$i] = '#EXT-X-MEDIA-SEQUENCE:'.$firstKeptNumber;
                break;
            }
        }

        return implode("\n", $out);
    }

    /**
     * A structurally valid live media playlist with no segments yet. HLS
     * clients (AVPlayer, ExoPlayer, hls.js) poll a live playlist without
     * EXT-X-ENDLIST, so this keeps the cold open alive until FFmpeg writes the
     * first segment instead of returning 503.
     */
    protected function emptyLivePlaylist(): Response
    {
        $segmentSeconds = max(1, (int) config('timeshift.segment_seconds', 2));
        $body = "#EXTM3U\n"
            ."#EXT-X-VERSION:3\n"
            ."#EXT-X-TARGETDURATION:{$segmentSeconds}\n"
            ."#EXT-X-MEDIA-SEQUENCE:0\n";

        return response($body, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    protected function proxyBroadcastPlaylistUrl(string $bufferId): string
    {
        return rtrim($this->proxyApiBase(), '/').'/broadcast/'.rawurlencode($bufferId).'/live.m3u8';
    }

    protected function fetchPlaylistWithStartupWait(string $playlistUrl): ?string
    {
        $waitSeconds = max(0, (int) config('timeshift.startup_wait_seconds', 2));
        $pollMs = max(50, (int) config('timeshift.startup_poll_ms', 100));
        $deadline = microtime(true) + $waitSeconds;
        $body = null;

        do {
            $body = $this->fetchPlaylistOnce($playlistUrl);
            if ($body !== null && $this->hasMinimumSegments($body)) {
                return $body;
            }
            if (microtime(true) >= $deadline) {
                return $body;
            }
            usleep($pollMs * 1000);
        } while (microtime(true) < $deadline);

        return $body;
    }

    protected function fetchPlaylistOnce(string $playlistUrl): ?string
    {
        try {
            $request = Http::timeout(10);
            $token = $this->proxyToken();
            if ($token) {
                $request = $request->withHeaders(['X-API-Token' => $token]);
            }
            $response = $request->get($playlistUrl);
            if (! $response->successful()) {
                return null;
            }

            return $response->body();
        } catch (\Throwable $e) {
            Log::debug('Timeshift: playlist fetch failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    protected function hasMinimumSegments(string $playlist): bool
    {
        $min = max(1, (int) config('timeshift.startup_min_segments', 1));
        $count = preg_match_all('/^live\d+\.ts$/m', $playlist);

        return $count >= $min;
    }

    protected function publicSegmentBase(string $bufferId): string
    {
        return rtrim($this->proxyPublicUrl(), '/').'/broadcast/'.rawurlencode($bufferId).'/segment/';
    }

    protected function proxyPublicUrl(): string
    {
        try {
            return app(M3uProxyService::class)->getPublicUrl();
        } catch (\Throwable) {
            return (string) config('proxy.m3u_proxy_public_url', '');
        }
    }

    protected function proxyApiBase(): string
    {
        $base = app(M3uProxyService::class)->getApiBaseUrl();
        if ($base === null || $base === '') {
            abort(503, 'Timeshift not configured');
        }

        return rtrim($base, '/');
    }

    protected function proxyToken(): ?string
    {
        return app(M3uProxyService::class)->getApiToken();
    }
}
