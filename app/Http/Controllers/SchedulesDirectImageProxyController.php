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

            // Short-circuit if this EPG already hit its daily download limit
            if (Cache::has("sd_download_limit_{$epgId}")) {
                return response()->json(['error' => 'Daily image download limit reached'], 429);
            }

            // Check cache first (cache for 24 hours)
            $cachedResponse = Cache::get($cacheKey);
            if ($cachedResponse) {
                if (isset($cachedResponse['not_found'])) {
                    return response()->json(['error' => 'Image not found'], 404);
                }

                if (isset($cachedResponse['download_limit'])) {
                    return response()->json(['error' => 'Daily image download limit reached'], 429);
                }

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
                $errorData = $response->json() ?: [];
                $sdCode = $errorData['code'] ?? null;

                Log::warning('Failed to fetch SchedulesDirect image', [
                    'epg_id' => $epgId,
                    'image_hash' => $imageHash,
                    'status' => $response->status(),
                    'sd_code' => $sdCode,
                    'response' => $response->body(),
                ]);

                // Image does not exist — cache the not-found state so we never re-request it
                if ($response->status() === 404 || $sdCode === SchedulesDirectService::IMAGE_NOT_FOUND_CODE) {
                    Cache::put($cacheKey, ['not_found' => true], now()->addHours(24));

                    return response()->json(['error' => 'Image not found'], 404);
                }

                // Download limit exceeded — cache at both the EPG and image level
                if (\in_array($sdCode, [SchedulesDirectService::EXCEED_DOWNLOAD_LIMIT_TRIAL_CODE, SchedulesDirectService::EXCEED_DOWNLOAD_LIMIT_CODE], true)) {
                    Cache::put("sd_download_limit_{$epgId}", true, now()->endOfDay());
                    Cache::put($cacheKey, ['download_limit' => true], now()->endOfDay());

                    return response()->json(['error' => 'Daily image download limit reached'], 429);
                }

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
