<?php

namespace App\Http\Controllers;

use App\Enums\ImageProfile;
use App\Exceptions\SchedulesDirectAuthBlockedException;
use App\Exceptions\SchedulesDirectRateLimitException;
use App\Models\Epg;
use App\Services\LogoCacheService;
use App\Services\SchedulesDirectService;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SchedulesDirectImageProxyController extends Controller
{
    /**
     * Set when Schedules Direct blocks this address (5004) after too many
     * not-found image requests. The block is per address, so it covers every EPG.
     */
    private const ADDRESS_BLOCKED_KEY = 'sd_image_address_blocked';

    /**
     * How long a not-found (5000) image is remembered. Schedules Direct asks for
     * the URI never to be requested again, and blocks the address after 1,000
     * not-found requests in a day.
     */
    private const NOT_FOUND_TTL_DAYS = 30;

    public function __construct(
        private SchedulesDirectService $schedulesDirectService
    ) {}

    /**
     * Proxy SchedulesDirect program images with authentication
     *
     * Route: /schedules-direct/{epg}/image/{imageHash}?v={lastUpdate}
     *
     * `v` is the image's lastUpdate (UNIX timestamp) from /metadata/programs.
     * SchedulesDirect URIs are ephemeral rather than content hashes, so a cached
     * copy older than `v` is downloaded again.
     */
    public function proxyImage(Request $request, string $epgId, string $imageHash)
    {
        $sourceKey = LogoCacheService::schedulesDirectSourceKey($epgId, $imageHash);
        $version = $request->query('v');
        $sourceUpdatedAt = is_string($version) && ctype_digit($version) ? (int) $version : null;
        // A cached copy found to be out of date, served if the refresh fails
        $staleCopy = null;

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

            // A cached copy is current unless the image changed upstream since
            // it was stored. A stale copy is still served whenever the image
            // can't be fetched again (download limit, address block, outage).
            $cachedProfile = ImageProfile::tryFrom((string) (LogoCacheService::readCacheMetadata($sourceKey)['profile'] ?? ''));
            $cacheFile = LogoCacheService::findImage($sourceKey, $cachedProfile);
            if ($cacheFile && ($sourceUpdatedAt === null || ! LogoCacheService::isOlderThan($sourceKey, $sourceUpdatedAt))) {
                return $this->imageResponse($cacheFile);
            }
            $staleCopy = $cacheFile;

            if (Cache::has(self::ADDRESS_BLOCKED_KEY)) {
                return $this->cachedCopyOrAddressBlocked($sourceKey);
            }

            // Short-circuit if this EPG already hit its daily download limit
            if (Cache::has("sd_download_limit_{$epgId}")) {
                return $this->cachedCopyOrDownloadLimit($sourceKey);
            }

            // Failed lookups are remembered so they are not re-requested
            $cacheKey = "sd_image_{$epgId}_{$imageHash}";
            $cachedFailure = Cache::get($cacheKey);
            if (isset($cachedFailure['not_found'])) {
                return $staleCopy ? $this->imageResponse($staleCopy) : response()->json(['error' => 'Image not found'], 404);
            }

            if (isset($cachedFailure['download_limit'])) {
                return $this->cachedCopyOrDownloadLimit($sourceKey);
            }

            // Ensure we have a valid token
            if (! $epg->hasValidSchedulesDirectToken()) {
                $this->schedulesDirectService->authenticateFromEpg($epg);
                $epg->refresh();
            }

            $response = $this->fetchImage($epg, $imageHash);
            $errorData = $this->errorBody($response);

            // Expired (4006) or missing (1004) token: discard it, log in once and retry.
            if (\in_array($errorData['code'] ?? null, [SchedulesDirectService::TOKEN_EXPIRED_CODE, SchedulesDirectService::NO_TOKEN_CODE], true)) {
                $this->schedulesDirectService->forgetToken($epg->sd_token);
                $epg->refresh();
                $this->schedulesDirectService->authenticateFromEpg($epg);
                $epg->refresh();

                $response = $this->fetchImage($epg, $imageHash);
                $errorData = $this->errorBody($response);
            }

            if ($errorData === null) {
                $body = $response->body();
                $contentType = $response->header('Content-Type') ?: 'image/jpeg';

                // The hash carries no role, so size by orientation: landscape
                // art as a backdrop, portrait and square art as a poster.
                $dimensions = @getimagesizefromstring($body);
                $profile = is_array($dimensions)
                    ? ImageProfile::forDimensions((int) $dimensions[0], (int) $dimensions[1])
                    : null;

                // Replace every copy of the old version, including other sizes
                LogoCacheService::clearBySourceKey($sourceKey);
                $cacheFile = LogoCacheService::storeImage($sourceKey, $body, $contentType, $profile, $sourceUpdatedAt);

                Log::debug('Successfully proxied SchedulesDirect image', [
                    'epg_id' => $epgId,
                    'image_hash' => $imageHash,
                    'content_type' => $contentType,
                    'fetched_bytes' => strlen($body),
                    'cached_bytes' => Storage::disk('local')->size($cacheFile),
                ]);

                return $this->imageResponse($cacheFile, $contentType);
            }

            $sdCode = $errorData['code'] ?? null;

            Log::warning('Failed to fetch SchedulesDirect image', [
                'epg_id' => $epgId,
                'image_hash' => $imageHash,
                'status' => $response->status(),
                'sd_code' => $sdCode,
                'response' => $response->body(),
            ]);

            // Image does not exist (5000, HTTP 404): remember it so it is not requested again
            if ($response->status() === 404 || $sdCode === SchedulesDirectService::IMAGE_NOT_FOUND_CODE) {
                Cache::put($cacheKey, ['not_found' => true], now()->addDays(self::NOT_FOUND_TTL_DAYS));

                return $staleCopy ? $this->imageResponse($staleCopy) : response()->json(['error' => 'Image not found'], 404);
            }

            // Daily download limit (5002/5003, sent as HTTP 200): cache at both the
            // EPG and image level until the counter resets at 00:00 UTC
            if (\in_array($sdCode, [SchedulesDirectService::EXCEED_DOWNLOAD_LIMIT_CODE, SchedulesDirectService::EXCEED_DOWNLOAD_LIMIT_TRIAL_CODE], true)) {
                $resetAt = now('UTC')->addDay()->startOfDay();
                Cache::put("sd_download_limit_{$epgId}", true, $resetAt);
                Cache::put($cacheKey, ['download_limit' => true], $resetAt);

                return $this->cachedCopyOrDownloadLimit($sourceKey);
            }

            // Account expired (4001): block the account as a /token rejection would
            if ($sdCode === SchedulesDirectService::ACCOUNT_EXPIRED_CODE) {
                $this->schedulesDirectService->rejectLogin($epg, $sdCode, $errorData);
            }

            // Address blocked by the load balancer (5004, HTTP 403) for a day
            if ($sdCode === SchedulesDirectService::IMAGE_ADDRESS_BLOCKED_CODE || ($response->status() === 403 && $sdCode === null)) {
                Cache::put(self::ADDRESS_BLOCKED_KEY, true, now()->addDay());

                return $this->cachedCopyOrAddressBlocked($sourceKey);
            }

            if ($staleCopy) {
                return $this->imageResponse($staleCopy);
            }

            return response()->json([
                'error' => 'Failed to fetch image from SchedulesDirect',
                'status' => $response->status(),
            ], $response->successful() ? 502 : $response->status());
        } catch (SchedulesDirectRateLimitException $e) {
            $cachedCopy = LogoCacheService::findAnyCopy($sourceKey);
            if ($cachedCopy) {
                return $this->imageResponse($cachedCopy);
            }

            // Provider login-limit cooldown or backoff is active; do not attempt to log in.
            Log::warning('SchedulesDirect image proxy skipped during login cooldown', [
                'epg_id' => $epgId,
                'retry_at' => $e->retryAt->toIso8601String(),
            ]);

            return response()->json([
                'error' => 'SchedulesDirect login limit reached; try again later',
                'retry_at' => $e->retryAt->toIso8601String(),
            ], 429);
        } catch (SchedulesDirectAuthBlockedException) {
            // The account's login was permanently rejected; never retry it per image.
            $cachedCopy = LogoCacheService::findAnyCopy($sourceKey);
            if ($cachedCopy) {
                return $this->imageResponse($cachedCopy);
            }

            return response()->json(['error' => 'SchedulesDirect login rejected for this account'], 403);
        } catch (\Exception $e) {
            Log::error('Exception in SchedulesDirect image proxy', [
                'epg_id' => $epgId,
                'image_hash' => $imageHash,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($staleCopy) {
                return $this->imageResponse($staleCopy);
            }

            return response()->json([
                'error' => 'Internal server error while proxying image',
            ], 500);
        }
    }

    private function fetchImage(Epg $epg, string $imageHash): Response
    {
        return Http::withHeaders([
            'User-Agent' => 'm3u-editor/'.config('dev.version'),
            'token' => $epg->sd_token,
        ])->timeout(30)->get("https://json.schedulesdirect.org/20141201/image/{$imageHash}");
    }

    /**
     * The JSON error body of an /image response, or null when it carried an
     * image. Schedules Direct reports the daily download limit (5002/5003) as
     * HTTP 200 with a JSON body, so a 200 is only an image when it is not JSON.
     *
     * @return array<string, mixed>|null
     */
    private function errorBody(Response $response): ?array
    {
        $isJson = str_contains((string) $response->header('Content-Type'), 'json')
            || str_starts_with(ltrim(substr($response->body(), 0, 16)), '{');

        if ($response->successful() && ! $isJson) {
            return null;
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * While Schedules Direct blocks this address (5004), serve any cached copy
     * and otherwise report the block without contacting the provider.
     */
    private function cachedCopyOrAddressBlocked(string $sourceKey): StreamedResponse|JsonResponse
    {
        $cachedCopy = LogoCacheService::findAnyCopy($sourceKey);

        return $cachedCopy
            ? $this->imageResponse($cachedCopy)
            : response()->json(['error' => 'SchedulesDirect image downloads are blocked for this address; try again later'], 429);
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
