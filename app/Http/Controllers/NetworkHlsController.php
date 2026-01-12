<?php

namespace App\Http\Controllers;

use App\Models\Network;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class NetworkHlsController extends Controller
{
    /**
     * Serve the HLS playlist (live.m3u8) for a network.
     */
    public function playlist(Request $request, Network $network): Response
    {
        if (! $network->broadcast_enabled) {
            return response('Broadcast not enabled for this network', 404);
        }

        $playlistPath = $network->getHlsStoragePath().'/live.m3u8';

        if (! File::exists($playlistPath)) {
            // Broadcast is enabled but not yet started or no segments yet
            return response('Broadcast not started or no segments available', 503)
                ->header('Retry-After', '5');
        }

        $content = File::get($playlistPath);

        return response($content, 200)
            ->header('Content-Type', 'application/vnd.apple.mpegurl')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0')
            ->header('Access-Control-Allow-Origin', '*');
    }

    /**
     * Serve an HLS segment file for a network.
     */
    public function segment(Request $request, Network $network, string $segment): BinaryFileResponse|Response
    {
        if (! $network->broadcast_enabled) {
            return response('Broadcast not enabled for this network', 404);
        }

        $segmentPath = $network->getHlsStoragePath()."/{$segment}.ts";

        if (! File::exists($segmentPath)) {
            return response('Segment not found', 404);
        }

        return response()->file($segmentPath, [
            'Content-Type' => 'video/MP2T',
            'Cache-Control' => 'public, max-age=86400', // Segments can be cached
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
}
