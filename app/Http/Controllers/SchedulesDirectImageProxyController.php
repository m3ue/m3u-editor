<?php

namespace App\Http\Controllers;

use App\Enums\ImageProfile;
use App\Exceptions\SchedulesDirectRateLimitException;
use App\Models\Epg;
use App\Services\LogoCacheService;
use App\Services\SchedulesDirectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $sourceKey = LogoCacheService::schedulesDirectSourceKey($epgId, $imageHash);

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

            // Image hashes are content-addressed, so a cached copy never goes
            // stale and is served even after the daily download limit trips.
            $cachedProfile = ImageProfile::tryFrom((string) (LogoCacheService::readCacheMetadata($sourceKey)['profile'] ?? ''));
            $cacheFile = LogoCacheService::findImage($sourceKey, $cachedProfile);
            if ($cacheFile) {
                return $this->imageResponse($cacheFile);
            }

            // Short-circuit if this EPG already hit its daily download limit
            if (Cache::has("sd_download_limit_{$epgId}")) {
                return $this->cachedCopyOrDownloadLimit($sourceKey);
            }

            // Failed lookups are remembered so they are not re-requested
            $cacheKey = "sd_image_{$epgId}_{$imageHash}";
            $cachedFailure = Cache::get($cacheKey);
            if (isset($cachedFailure['not_found'])) {
                return response()->json(['error' => 'Image not found'], 404);
            }

            if (isset($cachedFailure['download_limit'])) {
                return $this->cachedCopyOrDownloadLimit($sourceKey);
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
                $contentType = $response->header('Content-Type') ?: 'image/jpeg';

                // The hash carries no role, so size by orientation: landscape
                // art as a backdrop, portrait and square art as a poster.
                $dimensions = @getimagesizefromstring($body);
                $profile = is_array($dimensions)
                    ? ImageProfile::forDimensions((int) $dimensions[0], (int) $dimensions[1])
                    : null;

                $cacheFile = LogoCacheService::storeImage($sourceKey, $body, $contentType, $profile);

                Log::debug('Successfully proxied SchedulesDirect image', [
                    'epg_id' => $epgId,
                    'image_hash' => $imageHash,
                    'content_type' => $contentType,
                    'fetched_bytes' => strlen($body),
                    'cached_bytes' => Storage::disk('local')->size($cacheFile),
                ]);

                return $this->imageResponse($cacheFile, $contentType);
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

                    return $this->cachedCopyOrDownloadLimit($sourceKey);
                }

                return response()->json([
                    'error' => 'Failed to fetch image from SchedulesDirect',
                    'status' => $response->status(),
                ], $response->status());
            }
        } catch (SchedulesDirectRateLimitException $e) {
            $cachedCopy = LogoCacheService::findAnyCopy($sourceKey);
            if ($cachedCopy) {
                return $this->imageResponse($cachedCopy);
            }

            // Provider login-limit cooldown is active; do not attempt to log in.
            Log::warning('SchedulesDirect image proxy skipped during login-limit cooldown', [
                'epg_id' => $epgId,
                'retry_at' => $e->retryAt->toIso8601String(),
            ]);

            return response()->json([
                'error' => 'SchedulesDirect login limit reached; try again later',
                'retry_at' => $e->retryAt->toIso8601String(),
            ], 429);
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

    /**
     * Once the daily download limit is reached, any cached copy of the image
     * (even one sized for an older setting) beats a 429.
     */
    private function cachedCopyOrDownloadLimit(string $sourceKey): StreamedResponse|JsonResponse
    {
        $cachedCopy = LogoCacheService::findAnyCopy($sourceKey);

        return $cachedCopy
            ? $this->imageResponse($cachedCopy)
            : response()->json(['error' => 'Daily image download limit reached'], 429);
    }

    private function imageResponse(string $cacheFile, ?string $contentType = null): StreamedResponse
    {
        return LogoCacheService::streamResponse($cacheFile, $contentType, 86400, [
            'X-Proxied-From' => 'SchedulesDirect',
        ]);
    }
}
