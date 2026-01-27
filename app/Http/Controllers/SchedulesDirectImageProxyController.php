<?php

namespace App\Http\Controllers;

use App\Models\Epg;
use App\Services\SchedulesDirectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SchedulesDirectImageProxyController extends Controller
{
    public function __construct(
        private SchedulesDirectService $schedulesDirectService
    ) {}

    /**
     * Proxy SchedulesDirect program images with authentication
     *
     * Route: /schedules-direct/{epg}/image/{imageHash}
     */
    public function proxyImage(Request $request, string $epgId, string $imageHash)
    {
        try {
            // Find the EPG
            $epg = Epg::where('uuid', $epgId)->first();
            if (! $epg) {
                return response()->json(['error' => 'EPG not found'], 404);
            }

            // Validate that this EPG uses SchedulesDirect
            if (! $epg->isSchedulesDirect()) {
                return response()->json(['error' => 'EPG does not use SchedulesDirect'], 400);
            }

            // Create cache key for this image
            $cacheKey = "sd_image_{$epgId}_{$imageHash}";

            // Check cache first (cache for 24 hours)
            $cachedResponse = Cache::get($cacheKey);
            if ($cachedResponse) {
                return response($cachedResponse['body'], 200, $cachedResponse['headers']);
            }

            // Ensure we have a valid token
            if (! $epg->hasValidSchedulesDirectToken()) {
                $this->schedulesDirectService->authenticateFromEpg($epg);
                $epg->refresh();
            }

            // Build the SchedulesDirect image URL
            $imageUrl = "https://json.schedulesdirect.org/20141201/image/{$imageHash}";

            // Fetch the image with authentication
            $response = Http::withHeaders([
                'User-Agent' => 'm3u-editor/'.config('dev.version'),
                'token' => $epg->sd_token,
            ])->timeout(30)->get($imageUrl);

            if ($response->successful()) {
                $body = $response->body();
                $contentType = $response->header('Content-Type', 'image/jpeg');

                // Prepare headers for the proxied response
                $headers = [
                    'Content-Type' => $contentType,
                    'Content-Length' => strlen($body),
                    'Cache-Control' => 'public, max-age=86400', // Cache for 24 hours
                    'X-Proxied-From' => 'SchedulesDirect',
                ];

                // Cache the successful response for 24 hours
                Cache::put($cacheKey, [
                    'body' => $body,
                    'headers' => $headers,
                ], now()->addHours(24));

                Log::debug('Successfully proxied SchedulesDirect image', [
                    'epg_id' => $epgId,
                    'image_hash' => $imageHash,
                    'content_type' => $contentType,
                    'size_bytes' => strlen($body),
                ]);

                return response($body, 200, $headers);
            } else {
                Log::warning('Failed to fetch SchedulesDirect image', [
                    'epg_id' => $epgId,
                    'image_hash' => $imageHash,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                return response()->json([
                    'error' => 'Failed to fetch image from SchedulesDirect',
                    'status' => $response->status(),
                ], $response->status());
            }
        } catch (\Exception $e) {
            Log::error('Exception in SchedulesDirect image proxy', [
                'epg_id' => $epgId,
                'image_hash' => $imageHash,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Internal server error while proxying image',
            ], 500);
        }
    }
}
