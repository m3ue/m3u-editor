<?php

namespace App\Http\Controllers;

use App\Models\Network;
use App\Services\M3uProxyService;
use App\Services\NetworkBroadcastService;
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

            // Rewrite segment URLs to go through our proxy route
            // FFmpeg outputs segment names like "live000001.ts" in the playlist
            // We need to rewrite them to full URLs: /m3u-proxy/broadcast/{uuid}/segment/live000001.ts
            $baseUrl = url("/network/{$network->uuid}");
            $playlist = preg_replace(
                '/^(live\d+\.ts)$/m',
                $baseUrl.'/$1',
                $playlist
            );

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

    protected function fetchPlaylistResponse(Network $network)
    {
        $request = Http::timeout(10)
            ->withHeaders([
                'X-API-Token' => $this->proxyService->getApiToken(),
            ]);

        $playlistUrl = $this->proxyService->getApiBaseUrl()."/broadcast/{$network->uuid}/live.m3u8";

        /** @var \Illuminate\Http\Client\Response $response */
        $response = $request->get($playlistUrl);

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
            /** @var \Illuminate\Http\Client\Response $response */
            $response = $request->get($playlistUrl);

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

        preg_match_all('/^live\d+\.ts$/m', $playlist, $matches);
        $segmentCount = count($matches[0] ?? []);

        return $segmentCount >= $minSegments;
    }
}
