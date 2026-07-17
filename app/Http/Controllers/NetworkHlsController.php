<?php

namespace App\Http\Controllers;

use App\Models\Network;
use App\Services\M3uProxyService;
use App\Services\NetworkBroadcastService;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class NetworkHlsController extends Controller
{
    protected M3uProxyService $proxyService;

    protected NetworkBroadcastService $broadcastService;

    public function __construct()
    {
        $this->proxyService = new M3uProxyService;
        $this->broadcastService = app(NetworkBroadcastService::class);
    }

    /**
     * Serve the HLS playlist (live.m3u8) for a network.
     *
     * Proxies the playlist content from m3u-proxy service.
     * We proxy the playlist (rather than redirect) to ensure:
     * 1. Consistent URL for the player (no redirect confusion)
     * 2. Segment URLs in the playlist resolve correctly to our domain
     * 3. Better compatibility with HLS players that have issues with redirects
     */
    public function playlist(Request $request, Network $network): Response
    {
        if (! $network->broadcast_enabled) {
            return response('Broadcast not enabled for this network', 404);
        }

        if ($network->enabled && $network->broadcast_requested && $network->broadcast_on_demand) {
            if (! $network->isBroadcasting()) {
                $lock = Cache::lock("network.on_demand.start.{$network->id}", 10);

                if ($lock->get()) {
                    try {
                        $network->refresh();

                        if (! $network->isBroadcasting()) {
                            $this->broadcastService->markConnectionSeen($network);
                            $this->broadcastService->startNow($network);
                            $network->refresh();
                        }
                    } finally {
                        $lock->release();
                    }
                }
            }
        }

        try {
            $response = $this->fetchPlaylistResponse($network);

            if (! $response->successful()) {
                return response('Broadcast not available', $response->status());
            }

            if ($this->broadcastService->subtitlesEnabledForCurrentBroadcast($network)) {
                // FFmpeg auto-converts a mapped subtitle stream to WebVTT and writes it
                // as a companion sub-playlist (live_vtt.m3u8, derived from the video
                // playlist's own filename) — but it never upgrades live.m3u8 itself
                // into a proper HLS master playlist referencing both renditions, even
                // with a subtitle stream mapped. Synthesize that master playlist here;
                // the video/subtitle sub-playlists are served unchanged via variant()'s
                // existing fetch-and-rewrite logic (built for this in #1291, but never
                // actually reachable before now — the proxy alone never emits
                // #EXT-X-STREAM-INF, confirmed by testing FFmpeg's HLS muxer directly).
                $playlist = $this->buildMasterPlaylistWithSubtitles($network);
            } else {
                // Rewrite segment URLs to go through our proxy route
                // FFmpeg outputs segment names like "live000001.ts" in the playlist
                // We need to rewrite them to full URLs: /m3u-proxy/broadcast/{uuid}/segment/live000001.ts
                $baseUrl = url("/network/{$network->uuid}");
                $playlist = preg_replace(
                    '/^(live\d+\.ts)$/m',
                    $baseUrl.'/$1',
                    $response->body()
                );
            }

            return response($playlist, 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Access-Control-Allow-Origin' => '*',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch broadcast playlist', [
                'network_id' => $network->id,
                'error' => $e->getMessage(),
            ]);

            return response('Broadcast not available', 503);
        }
    }

    /**
     * Serve an HLS segment file for a network.
     *
     * Proxies the request to the m3u-proxy service.
     */
    public function segment(Request $request, Network $network, string $segment): RedirectResponse|Response
    {
        if (! $network->broadcast_enabled) {
            return response('Broadcast not enabled for this network', 404);
        }

        if ($network->enabled && $network->broadcast_requested && $network->broadcast_on_demand) {
            $this->broadcastService->markConnectionSeen($network);

            if (! $network->isBroadcasting()) {
                $this->broadcastService->startRequested($network);
            }
        }

        $proxyUrl = $this->proxyService->getProxyBroadcastSegmentUrl($network, $segment);

        return redirect()->to($proxyUrl);
    }

    /**
     * Serve an HLS sub-playlist or segment referenced from the master playlist
     * when subtitles are enabled (video variant, subtitle variant, or their .ts/.vtt
     * segments). Unlike segment(), .m3u8 files are fetched and rewritten here (they're
     * playlists, not opaque binary segments) so their own references resolve back
     * through our domain; .ts/.vtt segments are redirected straight to the proxy.
     */
    public function variant(Request $request, Network $network, string $filename): RedirectResponse|Response
    {
        if (! $network->broadcast_enabled) {
            return response('Broadcast not enabled for this network', 404);
        }

        if (! str_ends_with($filename, '.m3u8')) {
            if ($network->enabled && $network->broadcast_requested && $network->broadcast_on_demand) {
                $this->broadcastService->markConnectionSeen($network);
            }

            return redirect()->to($this->proxyService->getProxyBroadcastFileUrl($network, $filename));
        }

        try {
            $http = Http::timeout(10);

            if ($token = $this->proxyService->getApiToken()) {
                $http = $http->withHeaders(['X-API-Token' => $token]);
            }

            $url = $this->proxyService->getApiBaseUrl()."/broadcast/{$network->uuid}/segment/{$filename}";
            $response = $http->get($url);

            if (! $response->successful()) {
                return response('Not available', $response->status());
            }

            $content = $this->rewriteVariantPlaylist($response->body(), $network);

            return response($content, 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Access-Control-Allow-Origin' => '*',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch HLS sub-playlist', [
                'network_id' => $network->id,
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return response('Not available', 503);
        }
    }

    /**
     * Synthesize an HLS master playlist referencing the video variant (the flat
     * live.m3u8 FFmpeg already produces) and the subtitle variant (the
     * live_vtt.m3u8 WebVTT sub-playlist FFmpeg automatically derives whenever a
     * subtitle stream is mapped — same base filename with a "_vtt" suffix before
     * the extension). Both are served through hls-variant/ (variant()), which
     * already knows how to fetch an arbitrary .m3u8 from the proxy and rewrite its
     * bare segment references back through our domain.
     *
     * DEFAULT=NO/AUTOSELECT=YES: available in the player's subtitle menu but not
     * forced on for every viewer — matches the operator's "make it available"
     * intent (enabling the preference), not "force it on".
     *
     * If the source has no subtitle stream at all, FFmpeg never produces
     * live_vtt.m3u8 despite subtitles being requested — the URI below then 404s,
     * which players tolerate by simply not offering that rendition.
     */
    protected function buildMasterPlaylistWithSubtitles(Network $network): string
    {
        $variantBaseUrl = url("/network/{$network->uuid}/hls-variant");

        return implode("\n", [
            '#EXTM3U',
            '#EXT-X-VERSION:3',
            '#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subs",NAME="Subtitle",DEFAULT=NO,AUTOSELECT=YES,URI="'.$variantBaseUrl.'/live_vtt.m3u8"',
            '#EXT-X-STREAM-INF:BANDWIDTH=1400000,SUBTITLES="subs"',
            $variantBaseUrl.'/live.m3u8',
            '',
        ]);
    }

    /**
     * Rewrite a video/subtitle variant sub-playlist's bare .ts/.vtt segment
     * references so they resolve back through our domain.
     */
    protected function rewriteVariantPlaylist(string $playlist, Network $network): string
    {
        $variantBaseUrl = url("/network/{$network->uuid}/hls-variant");

        return preg_replace(
            '/^(live[^\s]*\.(?:ts|vtt))$/m',
            $variantBaseUrl.'/$1',
            $playlist
        );
    }

    protected function fetchPlaylistResponse(Network $network): ClientResponse
    {
        $http = Http::timeout(10);

        if ($token = $this->proxyService->getApiToken()) {
            $http = $http->withHeaders(['X-API-Token' => $token]);
        }

        $playlistUrl = $this->proxyService->getApiBaseUrl()."/broadcast/{$network->uuid}/live.m3u8";

        $response = $http->get($playlistUrl);

        $waitSeconds = max(0, (int) config('proxy.broadcast_on_demand_startup_wait_seconds', 8));
        $pollMs = max(100, (int) config('proxy.broadcast_on_demand_startup_poll_ms', 400));
        $minSegments = max(1, (int) config('proxy.broadcast_on_demand_startup_min_segments', 3));

        $startedRecently = $network->broadcast_started_at
            && $network->broadcast_started_at->gte(now()->subSeconds(max(1, $waitSeconds + 2)));

        $hasStartupRunway = $response->successful() && $this->hasMinimumPlaylistSegments($response->body(), $minSegments);

        $shouldWaitForStartup = $network->broadcast_on_demand &&
            $network->broadcast_requested &&
            $network->isBroadcasting() &&
            $startedRecently &&
            (! $hasStartupRunway) &&
            ($response->successful() || in_array($response->status(), [404, 503], true));

        if (! $shouldWaitForStartup) {
            return $response;
        }

        // Use iteration count rather than wall-clock time so the loop is
        // deterministic under fake sleeps in tests and on slow CI machines.
        $maxIterations = (int) ceil($waitSeconds * 1000 / $pollMs);

        for ($i = 0; $i < $maxIterations; $i++) {
            Sleep::for($pollMs)->milliseconds();
            $response = $http->get($playlistUrl);

            if ($response->successful() && $this->hasMinimumPlaylistSegments($response->body(), $minSegments)) {
                return $response;
            }

            if (! $response->successful() && ! in_array($response->status(), [404, 503], true)) {
                return $response;
            }
        }

        return $response;
    }

    protected function hasMinimumPlaylistSegments(string $playlist, int $minSegments): bool
    {
        if ($minSegments <= 1) {
            return true;
        }

        // The raw playlist fetched from the proxy is always the flat video
        // playlist — FFmpeg itself never emits a master playlist (subtitles, when
        // enabled, are synthesized into one separately by buildMasterPlaylistWithSubtitles()
        // in playlist(), from the same flat live.m3u8 checked here).
        preg_match_all('/^live\d+\.ts$/m', $playlist, $matches);
        $segmentCount = count($matches[0] ?? []);

        return $segmentCount >= $minSegments;
    }
}
