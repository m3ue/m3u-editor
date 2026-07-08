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

            $playlist = $response->body();

            if ($this->isMasterPlaylist($playlist)) {
                // Subtitles are enabled for this network: the proxy returns a master
                // playlist referencing a video variant + subtitle variant playlist
                // instead of a flat list of .ts segments.
                $playlist = $this->rewriteMasterPlaylist($playlist, $network);
            } else {
                // Rewrite segment URLs to go through our proxy route
                // FFmpeg outputs segment names like "live000001.ts" in the playlist
                // We need to rewrite them to full URLs: /m3u-proxy/broadcast/{uuid}/segment/live000001.ts
                $baseUrl = url("/network/{$network->uuid}");
                $playlist = preg_replace(
                    '/^(live\d+\.ts)$/m',
                    $baseUrl.'/$1',
                    $playlist
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
     * Whether a playlist body is a master playlist (subtitles enabled) rather
     * than a flat media playlist of .ts segments.
     */
    protected function isMasterPlaylist(string $playlist): bool
    {
        return str_contains($playlist, '#EXT-X-STREAM-INF');
    }

    /**
     * Rewrite a master playlist's references to its video/subtitle variant
     * sub-playlists so they resolve back through our domain.
     */
    protected function rewriteMasterPlaylist(string $playlist, Network $network): string
    {
        $variantBaseUrl = url("/network/{$network->uuid}/hls-variant");

        // FFmpeg marks the subtitle rendition DEFAULT=YES, which forces it on for
        // every viewer. The operator enabling detection means "make it available",
        // not "force it on" — flip to available-but-off (AUTOSELECT=YES keeps it
        // selectable in the player's menu; a bare DEFAULT=NO can make some players
        // drop it from the menu entirely).
        $playlist = preg_replace(
            '/^(#EXT-X-MEDIA:TYPE=SUBTITLES.*?)DEFAULT=YES(.*)$/m',
            '$1DEFAULT=NO,AUTOSELECT=YES$2',
            $playlist
        );

        // The subtitle rendition's URI="live_0_vtt.m3u8" attribute.
        $playlist = preg_replace_callback(
            '/URI="([^"]+\.m3u8)"/',
            fn (array $matches) => 'URI="'.$variantBaseUrl.'/'.$matches[1].'"',
            $playlist
        );

        // The bare video-variant playlist reference line, e.g. "live_0.m3u8".
        return preg_replace(
            '/^(live[^\s]*\.m3u8)$/m',
            $variantBaseUrl.'/$1',
            $playlist
        );
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

        if ($this->isMasterPlaylist($playlist)) {
            // Master playlists (subtitles enabled) don't list .ts segments directly —
            // they're nested in the video variant sub-playlist. Checking that requires
            // an extra fetch we don't want on this hot path, so treat the presence of
            // a populated STREAM-INF line as sufficient evidence FFmpeg has started.
            return true;
        }

        preg_match_all('/^live\d+\.ts$/m', $playlist, $matches);
        $segmentCount = count($matches[0] ?? []);

        return $segmentCount >= $minSegments;
    }
}
