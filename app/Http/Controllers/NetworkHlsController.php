<?php

namespace App\Http\Controllers;

use App\Models\Network;
use App\Services\M3uProxyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class NetworkHlsController extends Controller
{
    protected M3uProxyService $proxyService;

    public function __construct()
    {
        $this->proxyService = new M3uProxyService;
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

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-API-Token' => $this->proxyService->getApiToken(),
                ])
                ->get($this->proxyService->getApiBaseUrl()."/broadcast/{$network->uuid}/live.m3u8");

            if (! $response->successful()) {
                return response('Broadcast not available', $response->status());
            }

            $playlist = $response->body();

            // Rewrite segment URLs to go through our proxy route
            // FFmpeg outputs segment names like "live000001.ts" in the playlist
            // We need to rewrite them to full URLs: /m3u-proxy/broadcast/{uuid}/segment/live000001.ts
            $baseUrl = url("/m3u-proxy/broadcast/{$network->uuid}/segment");
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

        $proxyUrl = $this->proxyService->getProxyBroadcastSegmentUrl($network, $segment);

        return redirect()->to($proxyUrl);
    }
}
