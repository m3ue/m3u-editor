<?php

namespace App\Services;

use App\Exceptions\SchedulesDirectAuthBlockedException;
use App\Exceptions\SchedulesDirectRateLimitException;
use App\Exceptions\SchedulesDirectServerException;
use App\Exceptions\SchedulesDirectTokenExpiredException;
use App\Facades\ProxyFacade;
use App\Models\Epg;
use Carbon\Carbon;
use Closure;
use Exception;
use Generator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonMachine\Items;
use Throwable;

/**
 * Service to interact with the SchedulesDirect API for EPG data.
 */
class SchedulesDirectService
{
    private const API_VERSION = '20141201';

    private const BASE_URL = 'https://json.schedulesdirect.org';

    private static string $USER_AGENT = 'm3u-editor/dev';

    private static bool $FETCH_PROGRAM_ARTWORK = false; // Enable fetching program artwork

    /**
     * Error code indicating the user's account is not enabled for debug routing.
     * When received, we must disable debug mode to prevent the user from being blocked.
     */
    private const DEBUG_NOT_ENABLED_CODE = 2055;

    private const LINEUP_ALREADY_IN_ACCOUNT_CODE = 2101;

    private const MAX_LINEUPS_CODE = 2102;

    private const LINEUP_NOT_IN_ACCOUNT_CODE = 2104;

    private const TOO_MANY_LINEUP_CHANGES_CODE = 2107;

    /** No token was sent with the request. */
    public const NO_TOKEN_CODE = 1004;

    // /image codes. Schedules Direct reuses the account and token codes on
    // /image (4001 account expired, 4006 token expired, 1004 no token), but these
    // are its own.

    /** Image not found (HTTP 404); the URI must not be requested again. */
    public const IMAGE_NOT_FOUND_CODE = 5000;

    /** Subscriber daily image download limit reached (HTTP 200 with a JSON body). */
    public const EXCEED_DOWNLOAD_LIMIT_CODE = 5002;

    /** Trial account daily image download limit reached (HTTP 200 with a JSON body). */
    public const EXCEED_DOWNLOAD_LIMIT_TRIAL_CODE = 5003;

    /** Over 1,000 not-found image requests in a day; the address is blocked for 1 day (HTTP 403). */
    public const IMAGE_ADDRESS_BLOCKED_CODE = 5004;

    /** Token has expired; the stored token must be discarded and re-requested once. */
    public const TOKEN_EXPIRED_CODE = 4006;

    /** Exceeded the maximum number of logins in 24 hours. */
    public const TOO_MANY_LOGINS_CODE = 4009;

    /** Too many distinct IP addresses used the account in 24 hours. */
    public const TOO_MANY_UNIQUE_IPS_CODE = 4010;

    // /token rejections that retrying can never fix. Only ACCOUNT_EXPIRED is
    // also returned by /image; the rest are only interpreted on a /token response.
    public const ACCOUNT_EXPIRED_CODE = 4001;

    public const INVALID_HASH_CODE = 4002;

    public const INVALID_USER_CODE = 4003;

    public const JSON_ACCESS_DISABLED_CODE = 4005;

    public const APPLICATION_DISABLED_CODE = 4007;

    public const ACCOUNT_INACTIVE_CODE = 4008;

    private const PERMANENT_LOGIN_ERRORS = [
        self::ACCOUNT_EXPIRED_CODE => 'ACCOUNT_EXPIRED',
        self::INVALID_HASH_CODE => 'INVALID_HASH',
        self::INVALID_USER_CODE => 'INVALID_USER',
        self::JSON_ACCESS_DISABLED_CODE => 'JSON_ACCOUNT_ACCESS_DISABLED',
        self::APPLICATION_DISABLED_CODE => 'APPLICATION_DISABLED',
        self::ACCOUNT_INACTIVE_CODE => 'ACCOUNT_INACTIVE',
    ];

    /**
     * Permanent rejections of one EPG's credentials rather than of the account,
     * so they only block the rows that send the same username and password.
     */
    private const CREDENTIAL_LOGIN_ERRORS = [self::INVALID_HASH_CODE, self::INVALID_USER_CODE];

    /** Too many failed logins; Schedules Direct locks the account for 15 minutes. */
    public const ACCOUNT_LOCKOUT_CODE = 4004;

    private const ACCOUNT_LOCKOUT_MINUTES = 15;

    /**
     * Length of the cooldown after a 4009/4010 rejection. Schedules Direct counts
     * its login limits over a rolling 24 hours from the first request.
     */
    private const LOGIN_LIMIT_COOLDOWN_HOURS = 24;

    /** Upper bound, in minutes, of the exponential backoff after a server error. */
    private const BACKOFF_MAX_MINUTES = 60;

    /**
     * Safety margin subtracted from the provider's real token expiry when persisting
     * it, so a request never goes out with a token that expires in flight.
     */
    private const TOKEN_EXPIRY_SKEW_SECONDS = 60;

    /**
     * Hard cap on a single /token HTTP call. A hung authentication must not pin
     * the per-account lock for its full TTL or stall the worker.
     */
    private const TOKEN_HTTP_TIMEOUT_SECONDS = 45;

    /** How long a single-flight authentication holds the per-account lock. */
    private const AUTH_LOCK_SECONDS = 30;

    /** How long a waiting worker blocks for the in-flight authentication to finish. */
    private const AUTH_LOCK_WAIT_SECONDS = 25;

    /**
     * The current EPG model being used for requests (used to check/update sd_debug)
     */
    private ?Epg $currentEpg = null;

    // Configuration constants for performance tuning
    private const MAX_STATIONS_PER_SYNC = null;      // Limit stations for faster processing

    // SchedulesDirect allows up to 5000 stationIDs per /schedules request; 500 keeps
    // response size/time comfortably under the server's 10-minute hard cutoff while
    // cutting the number of requests per sync by 10x versus the old chunk of 50.
    private const STATIONS_PER_CHUNK = 500;

    private const SCHEDULES_TIMEOUT = 240;           // Bumped alongside the larger chunk size above

    private const DEFAULT_TIMEOUT = 60;              // Default timeout

    private const CHUNK_DELAY_MICROSECONDS = 50000;  // Reduced delay (50ms)

    // Number of sub-groups each STATIONS_PER_CHUNK-sized /schedules response is split
    // into for program-fetching/progress purposes. This is a fixed COUNT, not a fixed
    // entry size: the /schedules response has one element per (stationID, date) pair,
    // so a fixed entry-size sub-chunk would silently multiply /programs requests with
    // sd_days_to_import (e.g. size 50 becomes ~7x more requests at 7 days). A fixed
    // count instead keeps ~10 sd_progress updates (and ~10 /programs requests) per
    // batch regardless of how many days are configured.
    private const PROGRESS_STEPS_PER_BATCH = 10;

    private const MAX_RETRIES = 2;                   // Fewer retries for speed

    // SchedulesDirect allows up to 5000 programIDs per /programs request. This response
    // is streamed straight to disk (see cacheProgramBatch's sink()), so there's
    // no memory cost to requesting the maximum and cutting the number of requests by 5x.
    private const PROGRAMS_BATCH_SIZE = 5000;

    // SchedulesDirect source-data cache in the EPG's folder (see SchedulesDirectCacheStore).
    // Kept out of epg-cache/ so clearing the guide cache doesn't force a full download.
    public const CACHE_FILE = 'schedules-direct.sqlite';

    // Programs read from the program cache, and held in memory, at a time.
    private const PROGRAM_CACHE_QUERY_SIZE = 1000;

    // Top-level /programs fields kept in the program cache: the ones
    // writeProgramToXMLTV() reads. Cast, crew and keywords are most of a
    // program's size and the guide never uses them.
    private const CACHED_PROGRAM_FIELDS = ['programID', 'md5', 'titles', 'episodeTitle150', 'descriptions', 'genres', 'metadata', 'contentRating'];

    // Artwork is not covered by a program's MD5, so cached artwork is fetched
    // again after this many days to pick up images added since.
    private const ARTWORK_REFRESH_DAYS = 7;

    // Programs SchedulesDirect had no artwork for are checked again sooner, since
    // art for new shows and episodes often arrives after they are first listed.
    private const MISSING_ARTWORK_RETRY_HOURS = 24;

    public function __construct()
    {
        // Set a more descriptive user agent
        self::$USER_AGENT = 'm3u-editor/'.config('dev.version');
    }

    /**
     * Build HTTP headers for SchedulesDirect API requests.
     * Includes RouteTo:debug header when sd_debug is enabled on the current EPG.
     */
    private function buildHeaders(?string $token = null): array
    {
        $headers = [
            'User-Agent' => self::$USER_AGENT,
        ];

        if ($token) {
            $headers['token'] = $token;
        }

        // Add debug routing header if sd_debug is enabled
        if ($this->currentEpg && $this->currentEpg->sd_debug) {
            $headers['RouteTo'] = 'debug';
            Log::debug('Adding RouteTo:debug header for SchedulesDirect request');
        }

        return $headers;
    }

    /**
     * Handle error code 2055 (debug not enabled) by disabling sd_debug on the EPG.
     * This prevents the user from being blocked if their account isn't enabled for debugging.
     */
    private function handleDebugNotEnabledError(): void
    {
        if ($this->currentEpg && $this->currentEpg->sd_debug) {
            Log::warning('SchedulesDirect returned code 2055 - disabling sd_debug to prevent user from being blocked', [
                'epg_id' => $this->currentEpg->id,
            ]);

            $this->currentEpg->update(['sd_debug' => false]);
            $this->currentEpg->refresh();
        }
    }

    /**
     * Set the current EPG model for tracking debug state
     */
    public function setCurrentEpg(?Epg $epg): self
    {
        $this->currentEpg = $epg;

        return $this;
    }

    /**
     * Generator to yield station chunks for memory-efficient processing
     */
    private function getStationChunks(array $stationIds, int $chunkSize): Generator
    {
        $totalStations = count($stationIds);
        for ($i = 0; $i < $totalStations; $i += $chunkSize) {
            yield array_slice($stationIds, $i, $chunkSize);
        }
    }

    /**
     * Generator to process schedules in memory-efficient chunks
     */
    private function processScheduleChunks(Epg $epg, SchedulesDirectCacheStore $cache, array $stationIds, array $dates): Generator
    {
        foreach ($this->getStationChunks($stationIds, self::STATIONS_PER_CHUNK) as $chunkIndex => $stationChunk) {
            $chunkNumber = $chunkIndex + 1;
            $success = false;

            // Retry logic
            for ($retry = 0; $retry < self::MAX_RETRIES && ! $success; $retry++) {
                if ($retry > 0) {
                    $sleepTime = min(30, (2 ** $retry));
                    sleep($sleepTime);
                }

                try {
                    $chunkSchedules = $this->getStationSchedules($epg, $cache, $stationChunk, $dates);

                    if (! empty($chunkSchedules)) {
                        $success = true;
                        yield $chunkSchedules;

                        // Clear the chunk data immediately
                        unset($chunkSchedules);
                    } else {
                        throw new Exception('Empty or invalid response received');
                    }
                } catch (SchedulesDirectTokenExpiredException $e) {
                    // Every later request would fail too; let syncEpgData() log in again.
                    throw $e;
                } catch (SchedulesDirectServerException $e) {
                    if ($retry === self::MAX_RETRIES - 1) {
                        throw $e;
                    }
                } catch (Exception $e) {
                    if ($retry === self::MAX_RETRIES - 1) {
                        Log::error("Max retries exceeded for chunk {$chunkNumber}, skipping");
                    }
                }
            }

            // Delay between chunks
            usleep(self::CHUNK_DELAY_MICROSECONDS);
        }
    }

    /**
     * The schedules for a chunk of stations, one entry per station-day. Only the
     * station-days whose MD5 from /schedules/md5 differs from the cached copy are
     * downloaded; the rest come from the schedule cache.
     *
     * @param  array<int, string>  $stationIds
     * @param  array<int, string>  $dates
     * @return array<int, array<string, mixed>>
     */
    private function getStationSchedules(Epg $epg, SchedulesDirectCacheStore $cache, array $stationIds, array $dates): array
    {
        $cachedMd5s = $cache->scheduleMd5s($stationIds, $dates);
        $currentMd5s = $this->getScheduleMd5s($epg->sd_token, $stationIds, $dates);

        $changedRequests = $unchangedDates = [];
        foreach ($stationIds as $stationId) {
            $changedDates = [];
            foreach ($dates as $date) {
                if ($currentMd5s !== null && ! isset($currentMd5s[$stationId][$date])) {
                    // No data for this station-day yet; requesting it would return nothing
                    continue;
                }
                // A null MD5 means the md5 request failed: download the station-day
                $currentMd5 = $currentMd5s[$stationId][$date] ?? null;
                if ($currentMd5 !== null && ($cachedMd5s[$stationId][$date] ?? null) === $currentMd5) {
                    $unchangedDates[$stationId][] = $date;
                } else {
                    $changedDates[] = $date;
                }
            }
            if ($changedDates !== []) {
                $changedRequests[] = ['stationID' => $stationId, 'date' => $changedDates];
            }
        }

        Log::debug('SchedulesDirect schedule MD5 comparison', [
            'epg_id' => $epg->id,
            'stations' => count($stationIds),
            'stations_with_changes' => count($changedRequests),
            'unchanged_station_days' => array_sum(array_map('count', $unchangedDates)),
        ]);

        $schedules = [];
        if ($changedRequests !== []) {
            $schedules = $this->getSchedules($epg->sd_token, $changedRequests);
            $this->cacheSchedules($cache, $schedules);
        }

        if ($unchangedDates !== []) {
            array_push($schedules, ...$cache->schedules($unchangedDates));
        }

        return $schedules;
    }

    /**
     * The current MD5 of every station-day that has data, keyed by station ID
     * then date, from POST /schedules/md5. Null when the request fails for a
     * reason other than an expired token or a server error, so the caller can
     * fall back to downloading every station-day.
     *
     * @param  array<int, string>  $stationIds
     * @param  array<int, string>  $dates
     * @return array<string, array<string, string>>|null
     */
    private function getScheduleMd5s(string $token, array $stationIds, array $dates): ?array
    {
        $stationRequests = array_map(fn (string $stationId) => ['stationID' => $stationId, 'date' => $dates], $stationIds);

        try {
            $response = $this->makeRequest('POST', '/schedules/md5', $stationRequests, $token)->json();
        } catch (SchedulesDirectTokenExpiredException|SchedulesDirectServerException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('SchedulesDirect /schedules/md5 request failed; downloading every station-day in this chunk', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $md5s = [];
        foreach ($stationIds as $stationId) {
            foreach ($dates as $date) {
                $entry = $response[$stationId][$date] ?? null;
                // A non-zero code (e.g. 7100 schedule queued) means there is no data to fetch
                if (is_array($entry) && (int) ($entry['code'] ?? 0) === 0 && ! empty($entry['md5'])) {
                    $md5s[$stationId][$date] = (string) $entry['md5'];
                }
            }
        }

        return $md5s;
    }

    /**
     * Store the station-days of a /schedules response in the schedule cache.
     * Error entries carry no metadata and are skipped.
     *
     * @param  array<int, array<string, mixed>>  $schedules
     */
    private function cacheSchedules(SchedulesDirectCacheStore $cache, array $schedules): void
    {
        $rows = [];
        foreach ($schedules as $schedule) {
            $stationId = $schedule['stationID'] ?? null;
            $date = $schedule['metadata']['startDate'] ?? null;
            $md5 = $schedule['metadata']['md5'] ?? null;
            if (! $stationId || ! $date || ! $md5 || ! is_array($schedule['programs'] ?? null)) {
                continue;
            }
            $rows[] = ['station_id' => (string) $stationId, 'date' => $date, 'md5' => $md5, 'schedule' => $schedule];
        }

        $cache->putSchedules($rows);
    }

    /**
     * Resolve the canonical token expiry from a /token response.
     *
     * Schedules Direct returns `tokenExpires` as the UNIX epoch at which the
     * token stops working. `datetime` / `serverTime` are the server's current
     * clock and must never be treated as an expiry. When `serverTime` is
     * present the remaining lifetime is measured on SD's clock, so a local
     * clock that runs behind cannot keep using a token SD has already expired.
     * When `tokenExpires` is absent we fall back to the documented 24-hour
     * lifetime. A small skew margin is subtracted so a request never leaves
     * with a token that lapses in flight.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveTokenExpiry(array $data): Carbon
    {
        $tokenExpires = $data['tokenExpires'] ?? null;
        $serverTime = $data['serverTime'] ?? null;

        $expiresAt = match (true) {
            is_numeric($tokenExpires) && is_numeric($serverTime) => now()->addSeconds(max(0, (int) $tokenExpires - (int) $serverTime)),
            // Carbon 3 returns a UTC instance from a bare timestamp; pin it to the
            // app timezone so it round-trips through the tz-naive datetime column.
            is_numeric($tokenExpires) => Carbon::createFromTimestamp((int) $tokenExpires, config('app.timezone')),
            default => now()->addDay(),
        };

        return $expiresAt->subSeconds(self::TOKEN_EXPIRY_SKEW_SECONDS);
    }

    /**
     * POST credentials to /token, transparently retrying once without the debug
     * header if the account is not enabled for debug routing (code 2055).
     *
     * @return array<string, mixed> the decoded response body
     */
    private function postToken(string $username, string $passwordHash, bool $withEpgHeaders): array
    {
        $headers = $withEpgHeaders
            ? $this->buildHeaders()
            : ['User-Agent' => self::$USER_AGENT];

        $payload = ['username' => $username, 'password' => $passwordHash];

        $response = $this->sendTokenRequest($headers, $payload);
        $data = $response->json() ?? [];

        if (($data['code'] ?? null) === self::DEBUG_NOT_ENABLED_CODE) {
            $this->handleDebugNotEnabledError();

            Log::debug('Retrying authentication without debug header');
            $response = $this->sendTokenRequest(['User-Agent' => self::$USER_AGENT], $payload);
            $data = $response->json() ?? [];
        }

        if ($response->serverError()) {
            throw new SchedulesDirectServerException("SchedulesDirect authentication failed with HTTP {$response->status()}", $response->status());
        }

        // Maintenance (3000 SERVICE_OFFLINE) is temporary whatever HTTP status it
        // arrives with, so it is backed off rather than treated as a rejection.
        if ($this->isServiceOffline($data)) {
            throw new SchedulesDirectServerException('SchedulesDirect is offline for maintenance (code '.($data['code'] ?? 'SERVICE_OFFLINE').')', (int) ($data['code'] ?? 0));
        }

        // Schedules Direct returns its rejections (4001, 4009, ...) with an HTTP
        // 4xx and a `code` in the body, which callers inspect. A 4xx without a
        // code came from something in between (proxy, WAF), so it is backed off
        // like a server error.
        if ($response->failed() && ! isset($data['code'])) {
            throw new SchedulesDirectServerException("SchedulesDirect authentication failed with HTTP {$response->status()}", $response->status());
        }

        return $data;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $payload
     *
     * @throws SchedulesDirectServerException when the request times out or cannot connect
     */
    private function sendTokenRequest(array $headers, array $payload): Response
    {
        try {
            return Http::withHeaders($headers)
                ->timeout(self::TOKEN_HTTP_TIMEOUT_SECONDS)
                ->post(self::BASE_URL.'/'.self::API_VERSION.'/token', $payload);
        } catch (ConnectionException $e) {
            throw new SchedulesDirectServerException("SchedulesDirect authentication request failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Authenticate with SchedulesDirect using raw credentials (no EPG persistence).
     *
     * @return array{token: string, expires: int}
     */
    public function authenticate(string $username, string $password): array
    {
        // The form actions (lineup search, test connection) call this repeatedly,
        // so reuse a still-valid token for the same credentials instead of
        // logging in again each time.
        $cacheKey = 'sd-token:'.hash('sha256', mb_strtolower(trim($username)).'|'.hash('sha1', $password));
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && ($cached['expires'] ?? 0) > now()->addSeconds(60)->getTimestamp()) {
            return $cached;
        }

        $data = $this->postToken($username, hash('sha1', $password), withEpgHeaders: false);

        if ($reason = $this->loginLimitReason($data)) {
            throw new SchedulesDirectRateLimitException($this->loginCooldownEndsAt($reason), reason: $reason);
        }

        if ($code = $this->permanentLoginErrorCode($data)) {
            throw new SchedulesDirectAuthBlockedException($this->permanentLoginErrorMessage($code, $data), $code);
        }

        if (isset($data['code']) && $data['code'] !== 0) {
            throw new Exception('Authentication error: '.($data['message'] ?? $data['response'] ?? 'Unknown error'));
        }

        $result = [
            'token' => $data['token'],
            'expires' => $this->resolveTokenExpiry($data)->getTimestamp(),
        ];

        Cache::put($cacheKey, $result, max(1, $result['expires'] - now()->getTimestamp()));

        return $result;
    }

    /**
     * Authenticate using an EPG model with stored credentials.
     *
     * Authentication is single-flight per Schedules Direct account: only one
     * worker may hold the per-account lock and issue a /token request at a time.
     * Workers that arrive while a peer is authenticating wait for the lock, then
     * reuse the token the peer stored instead of logging in again.
     *
     * Coordination is per provider account, not per EPG row: several EPGs may
     * share one Schedules Direct login, so a still-valid token is copied from a
     * sibling before requesting a new one, and a 4009 cooldown fences the whole
     * account.
     *
     * @return array{token: string, expires: int}
     *
     * @throws SchedulesDirectRateLimitException when a 4009 cooldown is active or freshly triggered
     */
    public function authenticateFromEpg(Epg $epg): array
    {
        if (! $epg->sd_username || ! $epg->sd_password) {
            throw new Exception('SchedulesDirect credentials not configured');
        }

        // Set the current EPG for debug header tracking
        $this->setCurrentEpg($epg);

        $this->assertLoginAllowed($epg);

        // Namespacing hash only, not a security boundary: keeps the lock key
        // stable and free of characters the cache store might choke on. Folded
        // to lowercase so rows that differ only by username casing still share
        // the lock.
        $lock = Cache::lock(
            'sd-auth:'.hash('sha256', mb_strtolower(trim((string) $epg->sd_username))),
            self::AUTH_LOCK_SECONDS,
        );

        try {
            $lock->block(self::AUTH_LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            // A peer is authenticating and is taking longer than our patience.
            // Reuse whatever it (or a sibling EPG) has stored by now, otherwise
            // give up for this run.
            $epg->refresh();
            if ($epg->hasValidSchedulesDirectToken()) {
                return ['token' => $epg->sd_token, 'expires' => $epg->sd_token_expires_at->getTimestamp()];
            }
            if ($reused = $this->reuseSiblingSchedulesDirectToken($epg)) {
                return $reused;
            }
            $this->assertLoginAllowed($epg);
            throw new Exception('Timed out waiting for a concurrent SchedulesDirect authentication');
        }

        try {
            // Re-check under the lock: a peer may have just refreshed the token,
            // or recorded a rejection, cooldown or backoff while we were waiting.
            // Every failure below is recorded before the lock is released, so a
            // waiting worker never repeats a login that just failed.
            $epg->refresh();
            if ($epg->hasValidSchedulesDirectToken()) {
                return ['token' => $epg->sd_token, 'expires' => $epg->sd_token_expires_at->getTimestamp()];
            }
            if ($reused = $this->reuseSiblingSchedulesDirectToken($epg)) {
                return $reused;
            }
            $this->assertLoginAllowed($epg);

            try {
                $data = $this->postToken($epg->sd_username, hash('sha1', $epg->sd_password), withEpgHeaders: true);
            } catch (SchedulesDirectServerException $e) {
                throw new SchedulesDirectRateLimitException($this->beginBackoff($epg), $e->getMessage(), $e, SchedulesDirectRateLimitException::REASON_SERVER_ERROR);
            }

            if ($reason = $this->loginLimitReason($data)) {
                $retryAt = $this->beginLoginCooldown($epg, $reason);
                throw new SchedulesDirectRateLimitException($retryAt, reason: $reason);
            }

            if ($code = $this->permanentLoginErrorCode($data)) {
                $this->rejectLogin($epg, $code, $data);
            }

            if ((isset($data['code']) && $data['code'] !== 0) || empty($data['token'])) {
                // Malformed response (no token, or a non-numeric code): back off
                // exponentially rather than retrying on the fixed schedule.
                $message = 'SchedulesDirect login failed: '.($data['message'] ?? $data['response'] ?? 'Unknown error');
                throw new SchedulesDirectRateLimitException($this->beginBackoff($epg), $message, reason: SchedulesDirectRateLimitException::REASON_LOGIN_FAILED);
            }

            $expiresAt = $this->resolveTokenExpiry($data);

            $epg->update([
                'sd_token' => $data['token'],
                'sd_token_expires_at' => $expiresAt,
                // A successful login clears any prior rate-limit or backoff state.
                'sd_login_cooldown_until' => null,
                'sd_cooldown_reason' => null,
                'sd_backoff_attempts' => 0,
            ]);

            // The provider account is authenticating again, so lift the cooldown
            // from every sibling EPG that shares this login.
            $this->clearSiblingSchedulesDirectLoginCooldown($epg);

            return [
                'token' => $data['token'],
                'expires' => $expiresAt->getTimestamp(),
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Copy a still-valid Schedules Direct token from another EPG that shares this
     * provider account onto $epg, so no /token request is needed. The provider
     * issues one token per account; sharing it avoids a redundant login.
     *
     * @return array{token: string, expires: int}|null
     */
    private function reuseSiblingSchedulesDirectToken(Epg $epg): ?array
    {
        if (! $epg->sd_username) {
            return null;
        }

        $sibling = Epg::query()
            ->schedulesDirectAccount((string) $epg->sd_username, $epg->user_id)
            ->whereKeyNot($epg->getKey())
            ->whereNotNull('sd_token')
            ->where('sd_token_expires_at', '>', now()->addSeconds(60))
            ->orderByDesc('sd_token_expires_at')
            ->first();

        if (! $sibling || ! $sibling->hasValidSchedulesDirectToken()) {
            return null;
        }

        $epg->update([
            'sd_token' => $sibling->sd_token,
            'sd_token_expires_at' => $sibling->sd_token_expires_at,
            'sd_login_cooldown_until' => null,
            'sd_cooldown_reason' => null,
        ]);

        Log::debug('SchedulesDirect token reused from a sibling EPG on the same account', [
            'epg_id' => $epg->id,
            'sibling_epg_id' => $sibling->id,
        ]);

        return [
            'token' => $sibling->sd_token,
            'expires' => $sibling->sd_token_expires_at->getTimestamp(),
        ];
    }

    /**
     * Clear the login-limit cooldown on every other EPG that shares this
     * provider account after a successful authentication.
     */
    private function clearSiblingSchedulesDirectLoginCooldown(Epg $epg): void
    {
        if (! $epg->sd_username) {
            return;
        }

        Epg::query()
            ->schedulesDirectAccount((string) $epg->sd_username, $epg->user_id)
            ->whereKeyNot($epg->getKey())
            ->where(fn ($query) => $query->whereNotNull('sd_login_cooldown_until')->orWhere('sd_backoff_attempts', '>', 0))
            ->update(['sd_login_cooldown_until' => null, 'sd_cooldown_reason' => null, 'sd_backoff_attempts' => 0]);
    }

    /**
     * Refuse to issue a /token request while the account has a recorded
     * permanent rejection, a login-limit cooldown or a server-error backoff.
     *
     * @throws SchedulesDirectAuthBlockedException
     * @throws SchedulesDirectRateLimitException
     */
    private function assertLoginAllowed(Epg $epg): void
    {
        if ($epg->isSchedulesDirectAuthBlocked()) {
            throw new SchedulesDirectAuthBlockedException(
                (string) ($epg->sd_auth_blocked_message ?: 'SchedulesDirect rejected the login for this account.'),
                (int) $epg->sd_auth_blocked_code,
            );
        }

        if ($retryAt = $epg->activeSchedulesDirectAccountCooldownUntil()) {
            throw new SchedulesDirectRateLimitException($retryAt, reason: $this->activeCooldownReason($epg));
        }
    }

    /**
     * Why the account's latest-ending cooldown was entered. Cooldowns recorded
     * before the reason was stored were all login-limit (4009) cooldowns.
     */
    private function activeCooldownReason(Epg $epg): string
    {
        $reason = $epg->sd_username
            ? Epg::query()
                ->schedulesDirectAccount((string) $epg->sd_username, $epg->user_id)
                ->where('sd_login_cooldown_until', '>', now())
                ->orderByDesc('sd_login_cooldown_until')
                ->value('sd_cooldown_reason')
            : $epg->sd_cooldown_reason;

        return $reason ?? SchedulesDirectRateLimitException::REASON_TOO_MANY_LOGINS;
    }

    /**
     * True for Schedules Direct's maintenance response (3000 SERVICE_OFFLINE, or
     * any other 3xxx service code).
     *
     * @param  array<string, mixed>  $data
     */
    private function isServiceOffline(array $data): bool
    {
        $code = $data['code'] ?? null;

        return ($data['response'] ?? null) === 'SERVICE_OFFLINE'
            || (is_numeric($code) && (int) $code >= 3000 && (int) $code < 4000);
    }

    /**
     * The rate-limit reason for a 4004/4009/4010 /token rejection, or null.
     *
     * @param  array<string, mixed>  $data
     */
    private function loginLimitReason(array $data): ?string
    {
        $code = $data['code'] ?? null;
        $response = $data['response'] ?? null;

        return match (true) {
            $code === self::TOO_MANY_LOGINS_CODE || $response === 'TOO_MANY_LOGINS' => SchedulesDirectRateLimitException::REASON_TOO_MANY_LOGINS,
            $code === self::TOO_MANY_UNIQUE_IPS_CODE || $response === 'TOO_MANY_UNIQUE_IPS' => SchedulesDirectRateLimitException::REASON_TOO_MANY_UNIQUE_IPS,
            $code === self::ACCOUNT_LOCKOUT_CODE || $response === 'ACCOUNT_LOCKOUT' => SchedulesDirectRateLimitException::REASON_ACCOUNT_LOCKOUT,
            default => null,
        };
    }

    /**
     * The code of a /token rejection that must not be retried automatically, or
     * null. Besides the known permanent codes this includes any other error code
     * Schedules Direct returns: only 5xx, timeouts and the 4004/4009/4010 limits
     * are worth retrying, so an unrecognised rejection waits for the user too.
     *
     * @param  array<string, mixed>  $data
     */
    private function permanentLoginErrorCode(array $data): ?int
    {
        $code = $data['code'] ?? null;
        if (is_int($code) && isset(self::PERMANENT_LOGIN_ERRORS[$code])) {
            return $code;
        }

        $byResponse = array_search($data['response'] ?? null, self::PERMANENT_LOGIN_ERRORS, true);
        if ($byResponse !== false) {
            return $byResponse;
        }

        return is_numeric($code) && (int) $code !== 0 ? (int) $code : null;
    }

    /**
     * Actionable, credential-free explanation of a permanent /token rejection.
     *
     * @param  array<string, mixed>  $data
     */
    private function permanentLoginErrorMessage(int $code, array $data): string
    {
        $expiredOn = is_numeric($data['accountExpiration'] ?? null)
            ? ' on '.Carbon::createFromTimestamp((int) $data['accountExpiration'], 'UTC')->toFormattedDateString()
            : '';

        return match ($code) {
            self::ACCOUNT_EXPIRED_CODE => "Your SchedulesDirect account expired{$expiredOn}".'. Renew it at schedulesdirect.org, then use "Retry SchedulesDirect Login" (or update the credentials) to resume syncing.',
            self::INVALID_HASH_CODE => 'SchedulesDirect rejected the password format. Update the credentials to resume syncing.',
            self::INVALID_USER_CODE => 'SchedulesDirect rejected the username or password. Update the credentials to resume syncing.',
            self::JSON_ACCESS_DISABLED_CODE => 'SchedulesDirect has disabled API access for this account. Contact SchedulesDirect support, then use "Retry SchedulesDirect Login" to resume syncing.',
            self::APPLICATION_DISABLED_CODE => 'SchedulesDirect has disabled access for this application. Contact SchedulesDirect support, then use "Retry SchedulesDirect Login" to resume syncing.',
            self::ACCOUNT_INACTIVE_CODE => 'Your SchedulesDirect account is inactive. Check it at schedulesdirect.org, then use "Retry SchedulesDirect Login" to resume syncing.',
            default => 'SchedulesDirect rejected the login: '.($data['message'] ?? $data['response'] ?? 'Unknown error')." (code {$code}). Check the account at schedulesdirect.org, then use \"Retry SchedulesDirect Login\" to resume syncing.",
        };
    }

    /**
     * Record a permanent rejection and throw it. Also used by the image proxy,
     * since /image returns ACCOUNT_EXPIRED (4001) once the account lapses.
     *
     * @param  array<string, mixed>  $data  the response body carrying the rejection
     *
     * @throws SchedulesDirectAuthBlockedException always
     */
    public function rejectLogin(Epg $epg, int $code, array $data): never
    {
        $message = $this->permanentLoginErrorMessage($code, $data);
        $this->blockAccount($epg, $code, $message);

        throw new SchedulesDirectAuthBlockedException($message, $code);
    }

    /**
     * Discard a token Schedules Direct reported as expired (4006) or missing,
     * from every EPG that stored it (siblings share one token per account), so
     * the next authentication requests a fresh one instead of reusing it.
     */
    public function forgetToken(?string $token): void
    {
        if (! $token) {
            return;
        }

        Epg::query()->where('sd_token', $token)->update(['sd_token' => null, 'sd_token_expires_at' => null]);
    }

    /**
     * Handle TOKEN_EXPIRED (4006) from any endpoint: discard the token everywhere
     * it is stored and throw, so syncEpgData() logs in once and retries the sync.
     *
     * @param  array<string, mixed>  $body  the 4006 response body
     *
     * @throws SchedulesDirectTokenExpiredException always
     */
    private function throwTokenExpired(?string $token, array $body, string $endpoint): never
    {
        $storedExpiry = $this->currentEpg?->sd_token_expires_at;

        $this->forgetToken($token);
        if ($this->currentEpg && $this->currentEpg->sd_token === $token) {
            $this->currentEpg->forceFill(['sd_token' => null, 'sd_token_expires_at' => null])->syncOriginalAttributes(['sd_token', 'sd_token_expires_at']);
        }

        Log::warning('SchedulesDirect token expired (4006); cleared stored token', [
            'endpoint' => $endpoint,
            'epg_id' => $this->currentEpg?->id,
            'stored_expires_at' => $storedExpiry?->toIso8601String(),
            // A large gap between these means the local clock disagrees with SD's.
            'server_time' => $body['serverTime'] ?? null,
            'local_time' => now()->getTimestamp(),
        ]);

        throw new SchedulesDirectTokenExpiredException('SchedulesDirect token expired (Code: 4006)');
    }

    /**
     * Record a permanent /token rejection so no further login is attempted until
     * the user acts. Account-level rejections block every EPG on the provider
     * account; a bad username or password only blocks the rows sending those
     * same credentials, so a mistyped sibling cannot block a working EPG.
     */
    private function blockAccount(Epg $epg, int $code, string $message): void
    {
        $this->updateAccount(
            $epg,
            [
                'sd_token' => null,
                'sd_token_expires_at' => null,
                'sd_auth_blocked_code' => $code,
                'sd_auth_blocked_message' => $message,
            ],
            in_array($code, self::CREDENTIAL_LOGIN_ERRORS, true)
                ? fn ($query) => $query->where('sd_password', $epg->sd_password)
                : null,
        );

        Log::warning('SchedulesDirect permanently rejected the login; scheduled syncs stopped until the user acts', [
            'epg_id' => $epg->id,
            'code' => $code,
        ]);
    }

    /**
     * Lift a recorded permanent rejection from every EPG on this provider
     * account, so the next sync tries to log in again. Used by the "Retry
     * SchedulesDirect Login" action once the user has fixed the account.
     */
    public function clearAuthBlock(Epg $epg): void
    {
        $this->updateAccount(
            $epg,
            ['sd_auth_blocked_code' => null, 'sd_auth_blocked_message' => null],
            fn ($query) => $query->whereNotNull('sd_auth_blocked_code'),
        );
    }

    /**
     * Enter the capped exponential backoff (1, 2, 4 ... 60 minutes) after a
     * server error, timeout or unrecognised failure. Shared by every EPG on the
     * provider account, like the login cooldown.
     */
    private function beginBackoff(Epg $epg): Carbon
    {
        $attempts = $epg->sd_backoff_attempts + 1;
        $minutes = min(2 ** min($attempts - 1, 6), self::BACKOFF_MAX_MINUTES);
        $retryAt = now()->addMinutes($minutes);

        $this->updateAccount($epg, [
            'sd_login_cooldown_until' => $retryAt,
            'sd_cooldown_reason' => SchedulesDirectRateLimitException::REASON_BACKOFF,
            'sd_backoff_attempts' => $attempts,
        ]);

        Log::warning('SchedulesDirect request failed; backing off', [
            'epg_id' => $epg->id,
            'attempt' => $attempts,
            'retry_at' => $retryAt->toIso8601String(),
        ]);

        return $retryAt;
    }

    /**
     * Reset the server-error backoff on every EPG of this provider account
     * after a sync completes.
     */
    private function clearBackoff(Epg $epg): void
    {
        $this->updateAccount(
            $epg,
            ['sd_login_cooldown_until' => null, 'sd_cooldown_reason' => null, 'sd_backoff_attempts' => 0],
            fn ($query) => $query->where('sd_backoff_attempts', '>', 0),
        );
    }

    /**
     * Write the given attributes to every EPG on this provider account and mirror
     * them onto the in-memory model as already persisted. $constrain narrows which
     * rows are written, so rows already in the target state keep their updated_at.
     *
     * @param  array<string, mixed>  $attributes
     * @return int the number of rows updated
     */
    private function updateAccount(Epg $epg, array $attributes, ?Closure $constrain = null): int
    {
        $updated = Epg::query()
            ->schedulesDirectAccount((string) $epg->sd_username, $epg->user_id)
            ->when($constrain, $constrain)
            ->update($attributes);

        $epg->forceFill($attributes)->syncOriginalAttributes(array_keys($attributes));

        return $updated;
    }

    /**
     * When a login cooldown for $reason ends: 15 minutes for an account lockout
     * (4004), otherwise 24 hours, since the 4009/4010 limits count over a rolling
     * 24 hour window.
     */
    private function loginCooldownEndsAt(string $reason): Carbon
    {
        return $reason === SchedulesDirectRateLimitException::REASON_ACCOUNT_LOCKOUT
            ? now()->addMinutes(self::ACCOUNT_LOCKOUT_MINUTES)
            : now()->addHours(self::LOGIN_LIMIT_COOLDOWN_HOURS);
    }

    /**
     * Enter a cooldown after a 4004/4009/4010 rejection (see loginCooldownEndsAt()).
     * The limit is enforced by the provider per account, so every EPG that shares
     * this login is fenced, not just the one that hit it. An already-active
     * cooldown is never pushed forward, so attempts that are locally suppressed
     * during the window cannot extend it.
     */
    private function beginLoginCooldown(Epg $epg, string $reason): Carbon
    {
        if ($active = $epg->activeSchedulesDirectAccountCooldownUntil()) {
            return $active;
        }

        $retryAt = $this->loginCooldownEndsAt($reason);

        $fenced = $this->updateAccount($epg, ['sd_login_cooldown_until' => $retryAt, 'sd_cooldown_reason' => $reason, 'sd_backoff_attempts' => 0]);

        Log::warning('SchedulesDirect login limit reached; entering cooldown', [
            'epg_id' => $epg->id,
            'reason' => $reason,
            'epgs_fenced' => $fenced,
            'retry_at' => $retryAt->toIso8601String(),
        ]);

        return $retryAt;
    }

    /**
     * Get server status
     */
    public function getStatus(string $token): array
    {
        $response = $this->makeRequest('GET', '/status', [], $token);

        return $response->json();
    }

    /**
     * Return the maximum number of lineups allowed for this account from the /status endpoint.
     * Falls back to 4 (the default for most accounts) if the value cannot be determined.
     */
    public function getAccountMaxLineups(string $token): int
    {
        try {
            $status = $this->getStatus($token);

            return (int) ($status['account']['maxLineups'] ?? 4);
        } catch (Exception) {
            return 4;
        }
    }

    /**
     * Get available countries
     * Results are cached for 5 minutes
     */
    public function getCountries(): array
    {
        return Cache::remember('schedules_direct_countries', 300, function () {
            $response = Http::withHeaders([
                'User-Agent' => self::$USER_AGENT,
            ])->get(self::BASE_URL.'/'.self::API_VERSION.'/available/countries');

            if ($response->failed()) {
                throw new Exception('Failed to get countries from SchedulesDirect');
            }

            return $response->json();
        });
    }

    /**
     * Get headends for a postal code
     */
    public function getHeadends(string $token, string $country, string $postalCode): array
    {
        $response = $this->makeRequest('GET', '/headends', [
            'country' => $country,
            'postalcode' => $postalCode,
        ], $token);

        return $response->json();
    }

    /**
     * Preview a lineup
     */
    public function previewLineup(string $token, string $lineupId): array
    {
        $response = $this->makeRequest('GET', "/lineups/preview/{$lineupId}", [], $token);

        return $response->json();
    }

    /**
     * Get lineups currently added to the account
     */
    public function getAccountLineups(string $token): array
    {
        $response = $this->makeRequest('GET', '/lineups', [], $token);

        return $response->json();
    }

    /**
     * Add a lineup to the account
     */
    public function addLineup(string $token, string $lineupId): array
    {
        try {
            $response = $this->makeRequest('PUT', "/lineups/{$lineupId}", [], $token);

            return $response->json();
        } catch (Exception $e) {
            match ($e->getCode()) {
                self::LINEUP_ALREADY_IN_ACCOUNT_CODE => null, // idempotent - already added
                self::MAX_LINEUPS_CODE => throw new Exception(
                    'Your SchedulesDirect account has reached the maximum number of allowed lineups. Remove an existing lineup before adding a new one.',
                    self::MAX_LINEUPS_CODE,
                    $e
                ),
                self::TOO_MANY_LINEUP_CHANGES_CODE => throw new Exception(
                    'You have exceeded the daily limit of 6 lineup changes on your SchedulesDirect account. Please try again tomorrow.',
                    self::TOO_MANY_LINEUP_CHANGES_CODE,
                    $e
                ),
                default => throw $e,
            };

            return ['code' => 0, 'message' => 'Lineup already in account'];
        }
    }

    /**
     * Remove a lineup from the account
     */
    public function removeLineup(string $token, string $lineupId): array
    {
        try {
            $response = $this->makeRequest('DELETE', "/lineups/{$lineupId}", [], $token);

            return $response->json();
        } catch (Exception $e) {
            match ($e->getCode()) {
                self::LINEUP_NOT_IN_ACCOUNT_CODE => null, // idempotent - already removed
                self::TOO_MANY_LINEUP_CHANGES_CODE => throw new Exception(
                    'You have exceeded the daily limit of 6 lineup changes on your SchedulesDirect account. Please try again tomorrow.',
                    self::TOO_MANY_LINEUP_CHANGES_CODE,
                    $e
                ),
                default => throw $e,
            };

            return ['code' => 0, 'message' => 'Lineup not in account'];
        }
    }

    /**
     * Return all account lineups as a keyed options array suitable for Filament Select fields.
     * Authenticates if the EPG token is missing or expired.
     *
     * @return array<string, string> lineup_id => "Name (transport)"
     */
    public function getAccountLineupsAsOptions(Epg $epg): array
    {
        if (! $epg->hasValidSchedulesDirectToken()) {
            $this->authenticateFromEpg($epg);
            $epg->refresh();
        }

        $userLineups = $this->getUserLineups($epg->sd_token);

        return collect($userLineups['lineups'] ?? [])
            ->mapWithKeys(fn ($lineup) => [$lineup['lineup'] => "{$lineup['name']} - {$lineup['lineup']} ({$lineup['transport']})"])
            ->all();
    }

    /**
     * Remove a specific lineup from the EPG's SD account, re-authenticating if needed.
     */
    public function removeLineupFromEpg(Epg $epg, string $lineupId): void
    {
        if (! $epg->hasValidSchedulesDirectToken()) {
            $this->authenticateFromEpg($epg);
            $epg->refresh();
        }

        $this->removeLineup($epg->sd_token, $lineupId);
    }

    /**
     * Remove the lineups configured on the EPG from the SD account, skipping any
     * lineup another EPG on the same SD account still uses.
     */
    public function removeConfiguredLineups(Epg $epg): void
    {
        $lineupsInUse = Epg::query()
            ->schedulesDirectAccount((string) $epg->sd_username, $epg->user_id)
            ->whereKeyNot($epg->getKey())
            ->pluck('sd_lineup_ids')
            ->flatten()
            ->all();

        foreach (array_diff($epg->sd_lineup_ids ?? [], $lineupsInUse) as $lineupId) {
            $this->removeLineupFromEpg($epg, $lineupId);
        }
    }

    /**
     * Get a lineup's stations, adding the lineup to the SD account first if it isn't subscribed yet.
     */
    private function getOrAddLineup(Epg $epg, string $lineupId): array
    {
        try {
            return $this->getLineup($epg->sd_token, $lineupId);
        } catch (Exception $e) {
            // 4003/4004 = lineup not found/not subscribed
            if ($e->getCode() !== self::LINEUP_NOT_IN_ACCOUNT_CODE
                && ! str_contains($e->getMessage(), 'not in account')
                && ! str_contains($e->getMessage(), 'not subscribed')
            ) {
                throw $e;
            }

            Log::debug("Adding lineup {$lineupId} to SchedulesDirect account", ['epg_id' => $epg->id]);
            $this->addLineup($epg->sd_token, $lineupId);

            return $this->getLineup($epg->sd_token, $lineupId);
        }
    }

    /**
     * The modified stamp of every lineup on the account, keyed by lineup ID, from
     * GET /status. Empty when /status can't be read, so every lineup downloads.
     *
     * @return array<string, string>
     */
    private function getAccountLineupModifiedStamps(Epg $epg): array
    {
        try {
            $status = $this->getStatus($epg->sd_token);
        } catch (SchedulesDirectTokenExpiredException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('SchedulesDirect /status request failed; downloading every lineup', [
                'epg_id' => $epg->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $stamps = [];
        foreach ($status['lineups'] ?? [] as $lineup) {
            if (is_array($lineup) && isset($lineup['lineup'], $lineup['modified'])) {
                $stamps[(string) $lineup['lineup']] = (string) $lineup['modified'];
            }
        }

        return $stamps;
    }

    /**
     * A lineup from the lineup cache when its modified stamp still matches
     * /status, otherwise downloaded (and added to the account if needed) and
     * cached. A lineup /status doesn't list yet is downloaded without caching.
     */
    private function getCachedLineup(Epg $epg, SchedulesDirectCacheStore $cache, string $lineupId, ?string $modified): array
    {
        $cachedLineup = $modified !== null ? $cache->lineup($lineupId, $modified) : null;
        if ($cachedLineup !== null) {
            return $cachedLineup;
        }

        $lineup = $this->getOrAddLineup($epg, $lineupId);

        if ($modified !== null && is_array($lineup['map'] ?? null) && is_array($lineup['stations'] ?? null)) {
            $cache->putLineup($lineupId, $modified, $lineup);
        }

        return $lineup;
    }

    /**
     * Get lineup details including stations
     */
    public function getLineup(string $token, string $lineupId): array
    {
        $response = $this->makeRequest('GET', "/lineups/{$lineupId}", [], $token);

        return $response->json();
    }

    /**
     * Get user's lineups
     */
    public function getUserLineups(string $token): array
    {
        $response = $this->makeRequest('GET', '/lineups', [], $token);

        return $response->json();
    }

    /**
     * Get schedules for station IDs
     */
    public function getSchedules(string $token, array $stationRequests): array
    {
        $response = $this->makeRequest('POST', '/schedules', $stationRequests, $token);

        return $response->json();
    }

    /**
     * Get program information
     */
    public function getPrograms(string $token, array $programIds): array
    {
        $response = $this->makeRequest('POST', '/programs', $programIds, $token);

        return $response->json();
    }

    /**
     * Get artwork for programs, keyed by the requested 14 character programID.
     *
     * The full programID only returns artwork made for that exact episode, and
     * SchedulesDirect answers INVALID_PROGRAMID (code 6000) when there is none,
     * which is the norm for daily shows like news. Series artwork is keyed on
     * the 10 character root programID, so anything still missing after the
     * first pass is retried against its root.
     *
     * $failedProgramIds receives the programs whose artwork request failed, as
     * opposed to programs SchedulesDirect has no artwork for.
     *
     * @param  array<int, string>  $programIds
     * @param  array<int, string>  $failedProgramIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getProgramArtwork(string $token, array $programIds, ?string $epgUuid = null, array &$failedProgramIds = []): array
    {
        $failedProgramIds = [];
        if (empty($programIds)) {
            return [];
        }

        try {
            Log::debug('Fetching program artwork from SchedulesDirect', [
                'program_count' => count($programIds),
            ]);

            $allArtwork = $this->fetchArtworkBatches($token, $programIds, $epgUuid, $failedProgramIds);

            $missingByRootId = [];
            foreach ($programIds as $programId) {
                if (! isset($allArtwork[$programId]) && strlen($programId) > 10) {
                    $missingByRootId[substr($programId, 0, 10)][] = $programId;
                }
            }

            if (! empty($missingByRootId)) {
                $failedRootIds = [];
                $rootArtwork = $this->fetchArtworkBatches($token, array_map('strval', array_keys($missingByRootId)), $epgUuid, $failedRootIds);
                foreach ($rootArtwork as $rootId => $artwork) {
                    foreach ($missingByRootId[$rootId] ?? [] as $programId) {
                        $allArtwork[$programId] = $artwork;
                    }
                }
                foreach ($failedRootIds as $rootId) {
                    array_push($failedProgramIds, ...$missingByRootId[$rootId] ?? []);
                }
                $failedProgramIds = array_values(array_unique($failedProgramIds));
            }

            Log::debug('Successfully fetched program artwork', [
                'programs_with_artwork' => count($allArtwork),
                'total_programs' => count($programIds),
                'root_ids_requested' => count($missingByRootId),
            ]);

            return $allArtwork;
        } catch (SchedulesDirectTokenExpiredException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Exception while fetching program artwork', [
                'error' => $e->getMessage(),
                'program_count' => count($programIds),
            ]);
            $failedProgramIds = $programIds;

            return [];
        }
    }

    /**
     * POST programIDs to /metadata/programs/ in batches of 500 (the API limit)
     * and return the selected artwork keyed by the programID SchedulesDirect
     * echoes back. IDs without artwork come back as an error object instead of
     * an image list and are left out. The IDs of batches whose request failed
     * are added to $failedProgramIds.
     *
     * @param  array<int, string>  $programIds
     * @param  array<int, string>  $failedProgramIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function fetchArtworkBatches(string $token, array $programIds, ?string $epgUuid, array &$failedProgramIds): array
    {
        $artwork = [];
        $batches = array_chunk($programIds, 500);

        foreach ($batches as $batchIndex => $batch) {
            Log::debug('Processing artwork batch', [
                'batch' => $batchIndex + 1,
                'batch_size' => count($batch),
            ]);

            // The correct endpoint requires a trailing slash: /metadata/programs/
            $response = Http::withHeaders($this->buildHeaders($token))->timeout(30)->post(self::BASE_URL.'/'.self::API_VERSION.'/metadata/programs/', $batch);

            $artworkData = $response->json();

            // Handle code 2055: debug not enabled - disable sd_debug and retry without debug header
            if (($artworkData['code'] ?? null) === self::DEBUG_NOT_ENABLED_CODE) {
                $this->handleDebugNotEnabledError();

                // Retry the request without the debug header
                Log::debug('Retrying artwork request without debug header');
                $response = Http::withHeaders($this->buildHeaders($token))->timeout(30)->post(self::BASE_URL.'/'.self::API_VERSION.'/metadata/programs/', $batch);
                $artworkData = $response->json();
            }

            if (($artworkData['code'] ?? null) === self::TOKEN_EXPIRED_CODE) {
                $this->throwTokenExpired($token, $artworkData, '/metadata/programs/');
            }

            if ($response->successful() && is_array($artworkData)) {
                foreach ($artworkData as $programArtwork) {
                    $programId = $programArtwork['programID'] ?? null;
                    $artworkItems = $programArtwork['data'] ?? [];

                    if ($programId && is_array($artworkItems) && array_is_list($artworkItems) && ! empty($artworkItems)) {
                        // Group and process all artwork types, not just the "best" one
                        $processedArtwork = $this->selectBestArtwork($artworkItems, $epgUuid);

                        if (! empty($processedArtwork)) {
                            $artwork[$programId] = $processedArtwork;
                        }
                    }
                }
            } else {
                array_push($failedProgramIds, ...$batch);
                Log::error('Failed to fetch program artwork batch', [
                    'batch' => $batchIndex + 1,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
            }

            // Add small delay between batches to be respectful to the API
            if ($batchIndex < count($batches) - 1) {
                usleep(100000); // 100ms delay
            }
        }

        return $artwork;
    }

    /**
     * Select only the best 1-2 images per type to avoid XMLTV bloat, plus the
     * best 16:9 image when none of those picks is one. Resolution scoring
     * favours the large square and portrait renditions, but guides frame
     * programme art as 16:9 and SchedulesDirect offers it for nearly every
     * program.
     */
    private function selectBestArtwork(array $artworkItems, ?string $epgUuid = null): array
    {
        $selectedArtwork = [];
        $typeGroups = [];

        // Group artwork by type
        foreach ($artworkItems as $artwork) {
            if (empty($artwork['uri'])) {
                continue;
            }

            $xmltvType = $this->mapSchedulesDirectCategoryToXMLTV($artwork['category'] ?? '');
            if (empty($xmltvType)) {
                continue;
            } // Skip unmappable types

            $typeGroups[$xmltvType][] = $artwork;
        }

        // Select the best 1-2 images per type
        foreach ($typeGroups as $type => $artworks) {
            // Sort by quality (prefer higher resolution and better tiers)
            usort($artworks, function ($a, $b) {
                $scoreA = $this->calculateArtworkScore($a);
                $scoreB = $this->calculateArtworkScore($b);

                return $scoreB <=> $scoreA; // Descending order (highest score first)
            });

            // Take only the best 1-2 images per type
            $limit = ($type === 'poster') ? 2 : 1; // Allow 2 posters, 1 of other types
            $selectedFromType = array_slice($artworks, 0, $limit);

            foreach ($selectedFromType as $artwork) {
                $selectedArtwork[] = $this->buildArtworkInfo($artwork, $type, $epgUuid);
            }
        }

        $hasWideArtwork = collect($selectedArtwork)->contains(
            fn (array $artwork): bool => $this->isWideArtwork($artwork)
        );
        if (! $hasWideArtwork) {
            $bestWide = null;
            foreach ($typeGroups as $type => $artworks) {
                foreach ($artworks as $artwork) {
                    if ($this->isWideArtwork($artwork)
                        && ($bestWide === null || $this->calculateArtworkScore($artwork) > $this->calculateArtworkScore($bestWide['artwork']))) {
                        $bestWide = ['type' => $type, 'artwork' => $artwork];
                    }
                }
            }

            if ($bestWide !== null) {
                $selectedArtwork[] = $this->buildArtworkInfo($bestWide['artwork'], $bestWide['type'], $epgUuid);
            }
        }

        Log::debug('Artwork selection completed', [
            'original_count' => count($artworkItems),
            'selected_count' => count($selectedArtwork),
            'types_found' => array_keys($typeGroups),
        ]);

        return $selectedArtwork;
    }

    /**
     * The XMLTV icon attributes for one SchedulesDirect image.
     *
     * @param  array<string, mixed>  $artwork
     *                                         `last_update` is the image's `lastUpdate` as a UNIX timestamp; the image
     *                                         proxy uses it to tell when its cached copy of the image is out of date.
     * @param  array<string, mixed>  $artwork
     * @return array{uri: string, last_update: ?int, url: string, type: string, width: int, height: int, orient: string, size: string, category: string, tier: string}
     */
    private function buildArtworkInfo(array $artwork, string $type, ?string $epgUuid): array
    {
        $lastUpdate = isset($artwork['lastUpdate']) ? (strtotime((string) $artwork['lastUpdate']) ?: null) : null;

        return [
            'uri' => $artwork['uri'],
            'last_update' => $lastUpdate,
            'url' => $this->buildImageUrl($artwork['uri'], $epgUuid, $lastUpdate),
            'type' => $type,
            'width' => $artwork['width'] ?? 0,
            'height' => $artwork['height'] ?? 0,
            'orient' => $this->determineOrientation($artwork['width'] ?? 0, $artwork['height'] ?? 0),
            'size' => $this->mapImageSize($artwork['width'] ?? 0, $artwork['height'] ?? 0),
            'category' => $artwork['category'] ?? '',
            'tier' => $artwork['tier'] ?? '',
        ];
    }

    /**
     * Whether an image is a 16:9 rendition, from the SchedulesDirect `aspect`
     * field or, for already-selected artwork, its dimensions.
     *
     * @param  array<string, mixed>  $artwork
     */
    private function isWideArtwork(array $artwork): bool
    {
        if (isset($artwork['aspect'])) {
            return $artwork['aspect'] === '16x9';
        }

        $width = (int) ($artwork['width'] ?? 0);
        $height = (int) ($artwork['height'] ?? 0);

        return $height > 0 && abs($width / $height - 16 / 9) < 0.05;
    }

    /**
     * Calculate a quality score for artwork to prioritize the best images
     */
    private function calculateArtworkScore(array $artwork): int
    {
        $score = 0;

        // Resolution scoring (higher resolution = better)
        $width = $artwork['width'] ?? 0;
        $height = $artwork['height'] ?? 0;
        $pixels = $width * $height;

        if ($pixels >= 1000000) {
            $score += 100;
        } // 1MP+
        elseif ($pixels >= 500000) {
            $score += 80;
        }  // 500K+
        elseif ($pixels >= 250000) {
            $score += 60;
        }  // 250K+
        elseif ($pixels >= 100000) {
            $score += 40;
        }  // 100K+
        else {
            $score += 20;
        } // Small images

        // Tier scoring (Episode > Season > Series)
        $tier = strtolower($artwork['tier'] ?? '');
        switch ($tier) {
            case 'episode':
                $score += 50;
                break;
            case 'season':
                $score += 40;
                break;
            case 'series':
                $score += 30;
                break;
            default:
                $score += 20;
                break;
        }

        // Category scoring (prefer iconic/poster over banners)
        $category = strtolower($artwork['category'] ?? '');
        if (str_contains($category, 'iconic')) {
            $score += 30;
        } elseif (str_contains($category, 'poster')) {
            $score += 25;
        } elseif (str_contains($category, 'banner-l1')) {
            $score += 20;
        } elseif (str_contains($category, 'banner')) {
            $score += 10;
        }

        return $score;
    }

    /**
     * Build the complete image URL from the URI. Proxy URLs carry the image's
     * lastUpdate as `?v=`, so the proxy refreshes its cached copy when the image
     * changes (SchedulesDirect URIs are ephemeral, not content hashes).
     */
    private function buildImageUrl(string $uri, ?string $epgUuid = null, ?int $lastUpdate = null): string
    {
        // If URI is already a complete URL (starts with https://), return as-is
        if (str_starts_with($uri, 'https://')) {
            return $uri;
        }

        // If we have an EPG UUID, use the proxy URL
        if ($epgUuid) {
            return ProxyFacade::getBaseUrl().'/schedules-direct/'.$epgUuid.'/image/'.$uri
                .($lastUpdate ? '?v='.$lastUpdate : '');
        }

        // Fallback to direct URL (will require authentication)
        return self::BASE_URL.'/'.self::API_VERSION.'/image/'.$uri;
    }

    /**
     * Map SchedulesDirect artwork categories to XMLTV image types
     */
    private function mapSchedulesDirectCategoryToXMLTV(string $category): string
    {
        return match (strtolower($category)) {
            // Main poster/iconic images
            'iconic' => 'poster',
            'poster art', 'box art' => 'poster',

            // Banner images (usually landscape) - map to backdrop
            'banner', 'banner-l1', 'banner-l2', 'banner-l3', 'banner-lo', 'banner-lot' => 'backdrop',

            // Still images from shows/movies
            'scene still', 'photo', 'still' => 'still',

            // People images
            'cast ensemble', 'cast in character' => 'character',
            'photo-headshot' => 'person',

            // Logo/branding - not typically used in XMLTV image tags, skip
            'logo', 'staple' => '', // Return empty to skip

            default => 'poster' // Default to poster for unrecognized categories
        };
    }

    /**
     * Determine image orientation from dimensions
     */
    private function determineOrientation(int $width, int $height): string
    {
        if ($width == 0 || $height == 0) {
            return 'P'; // Default to portrait
        }

        return $width > $height ? 'L' : 'P'; // Landscape or Portrait
    }

    /**
     * Map image dimensions to XMLTV size (1=small, 2=medium, 3=large)
     */
    private function mapImageSize(int $width, int $height): string
    {
        $totalPixels = $width * $height;

        if ($totalPixels >= 1000000) { // >= ~1000x1000
            return '3'; // Large
        } elseif ($totalPixels >= 250000) { // >= ~500x500
            return '2'; // Medium
        } else {
            return '1'; // Small
        }
    }

    /**
     * Extract station artwork directly from lineup data
     * Station logos are included in the lineup response, not from a separate API
     */
    private function extractStationArtworkFromLineup(array $lineupData): array
    {
        $stationArtworkCache = [];

        try {
            if (! empty($lineupData['stations'])) {
                Log::debug('Extracting station artwork from lineup', ['station_count' => count($lineupData['stations'])]);

                foreach ($lineupData['stations'] as $station) {
                    $stationId = $station['stationID'] ?? null;
                    if ($stationId && ! empty($station['stationLogo'])) {
                        foreach ($station['stationLogo'] as $logo) {
                            if (! empty($logo['URL'])) {
                                $stationArtworkCache[$stationId] = $logo['URL'];
                                break; // Use first available logo
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            Log::warning('Failed to extract station artwork from lineup', ['error' => $e->getMessage()]);
        }

        Log::debug('Station artwork cache built', ['stations' => count($stationArtworkCache)]);

        return $stationArtworkCache;
    }

    /**
     * Fetch SchedulesDirect EPG data and update the EPG record.
     *
     * If the provider reports TOKEN_EXPIRED (4006) mid-sync, the stored token has
     * already been cleared; we re-authenticate exactly once and retry the sync.
     *
     * @throws SchedulesDirectRateLimitException when a 4009 login-limit cooldown is active
     */
    public function syncEpgData(Epg $epg): void
    {
        // Set the current EPG for debug header tracking
        $this->setCurrentEpg($epg);

        Log::debug('Starting SchedulesDirect sync', [
            'epg_id' => $epg->id,
            'chunk_size' => self::STATIONS_PER_CHUNK,
            'sd_debug' => $epg->sd_debug,
        ]);

        $reauthAttempted = false;

        while (true) {
            try {
                $this->performEpgSync($epg);
                $this->clearBackoff($epg);

                return;
            } catch (SchedulesDirectServerException $e) {
                // A data endpoint failed with a 5xx or timeout: back off
                // exponentially instead of retrying on the fixed schedule.
                throw new SchedulesDirectRateLimitException($this->beginBackoff($epg), $e->getMessage(), $e, SchedulesDirectRateLimitException::REASON_SERVER_ERROR);
            } catch (SchedulesDirectTokenExpiredException $e) {
                if ($reauthAttempted) {
                    throw $e;
                }

                $reauthAttempted = true;
                Log::warning('SchedulesDirect token expired mid-sync (4006); re-authenticating once', [
                    'epg_id' => $epg->id,
                ]);
                $this->authenticateFromEpg($epg);
                $epg->refresh();
            }
        }
    }

    private function performEpgSync(Epg $epg): void
    {
        $cache = null;
        try {
            // Validate token or re-authenticate
            if (! $epg->hasValidSchedulesDirectToken()) {
                $this->authenticateFromEpg($epg);
            }

            // Get lineup data
            if (! $epg->hasSchedulesDirectLineup()) {
                throw new Exception('No lineup configured for SchedulesDirect EPG');
            }

            // Set the metadata fetching flag
            self::$FETCH_PROGRAM_ARTWORK = $epg->sd_metadata['enabled'] ?? false;

            // Reset EPG SD sync status
            $epg->update([
                'sd_errors' => null,
                'sd_last_sync' => null,
                'sd_progress' => 0,
            ]);

            // Merge every selected lineup; a station carried by more than one lineup is kept once.
            // A lineup that can't be added to the account (slot or daily change limit) is recorded
            // and skipped so the others still import. It was never imported, so skipping it loses
            // no channel mappings. Any other failure fails the sync, because an import missing a
            // previously imported lineup would delete its EPG channels and their mappings.
            $cache = SchedulesDirectCacheStore::open(Storage::disk('local')->path("{$epg->folder_path}/".self::CACHE_FILE));
            $cache->beginSync();

            $map = $stations = $lineupErrors = [];
            $lineupModifiedStamps = $this->getAccountLineupModifiedStamps($epg);
            foreach ($epg->sd_lineup_ids as $lineupId) {
                try {
                    $lineup = $this->getCachedLineup($epg, $cache, $lineupId, $lineupModifiedStamps[$lineupId] ?? null);
                } catch (Exception $e) {
                    if (! in_array($e->getCode(), [self::MAX_LINEUPS_CODE, self::TOO_MANY_LINEUP_CHANGES_CODE], true)) {
                        throw $e;
                    }
                    $lineupErrors[] = [
                        'timestamp' => now()->toISOString(),
                        'message' => "Lineup {$lineupId}: {$e->getMessage()}",
                    ];
                    Log::warning('Skipping SchedulesDirect lineup that could not be added to the account', [
                        'epg_id' => $epg->id,
                        'lineup_id' => $lineupId,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }
                if (! is_array($lineup['map'] ?? null) || ! is_array($lineup['stations'] ?? null)) {
                    throw new Exception("SchedulesDirect returned an invalid lineup for {$lineupId}");
                }
                foreach ($lineup['map'] as $mapping) {
                    $map[$mapping['stationID']] ??= $mapping;
                }
                foreach ($lineup['stations'] as $station) {
                    $stations[$station['stationID']] ??= $station;
                }
            }
            if (count($lineupErrors) === count($epg->sd_lineup_ids)) {
                throw new Exception(implode(' ', array_column($lineupErrors, 'message')));
            }
            // An empty lineup would make the import delete every existing EPG channel and its mappings
            if ($map === [] && $epg->channels()->exists()) {
                throw new Exception('SchedulesDirect returned no stations; keeping the existing EPG data');
            }
            $lineupData = ['map' => array_values($map), 'stations' => array_values($stations)];

            // Refresh station IDs from the current lineups on every sync so stations
            // Schedules Direct removes/remaps server-side don't linger and get
            // requested after they're no longer valid (causes SD to block the app).
            $stationIds = array_column($lineupData['map'], 'stationID');
            $epg->update(['sd_station_ids' => $stationIds]);

            // Use limited stations for faster processing
            $stationIds = self::MAX_STATIONS_PER_SYNC
                ? array_slice($stationIds, 0, self::MAX_STATIONS_PER_SYNC)
                : $stationIds;

            Log::debug('Starting SchedulesDirect sync', [
                'epg_id' => $epg->id,
                'station_count' => count($stationIds),
                'chunk_size' => self::STATIONS_PER_CHUNK,
            ]);

            // Generate dates
            $dates = [];
            for ($i = 0; $i < $epg->sd_days_to_import; $i++) {
                $dates[] = Carbon::now()->addDays($i)->format('Y-m-d');
            }

            // Stream process schedules and build XMLTV on the fly
            $xmlFilePath = $this->streamProcessToXMLTV($epg, $cache, $lineupData, $stationIds, $dates);

            // Update EPG record
            $epg->update([
                'sd_last_sync' => now(),
                'sd_errors' => $lineupErrors ?: null,
                'sd_progress' => 100,
            ]);
            Log::debug('Successfully completed SchedulesDirect sync', [
                'epg_id' => $epg->id,
                'stations_processed' => count($stationIds),
                'file_path' => $xmlFilePath,
            ]);
        } catch (SchedulesDirectTokenExpiredException $e) {
            // Handled by the single retry in syncEpgData(); do not record as a
            // sync error on the first occurrence.
            throw $e;
        } catch (SchedulesDirectRateLimitException $e) {
            // Provider login-limit cooldown. Surface it unchanged so the caller
            // can back off; recording it every 16 minutes would just be noise.
            throw $e;
        } catch (Exception $e) {
            $errors = $epg->sd_errors ?? [];
            $errors[] = [
                'timestamp' => now()->toISOString(),
                'message' => $e->getMessage(),
            ];

            $epg->update(['sd_errors' => $errors]);
            Log::error('Failed to sync SchedulesDirect EPG data', [
                'epg_id' => $epg->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $cache?->close();
        }
    }

    /**
     * Stream process data directly to XMLTV file to minimize memory usage
     * Optimized version that processes schedules and programs in a single pass
     */
    private function streamProcessToXMLTV(
        Epg $epg,
        SchedulesDirectCacheStore $cache,
        array $lineupData,
        array $stationIds,
        array $dates
    ): string {
        // Write next to the current guide file and only replace it once complete,
        // like the URL import's download, so a failed sync keeps serving the last
        // good guide. The name is fixed, so an interrupted sync leaves at most one
        // leftover, which the next sync (or deleting the EPG) removes.
        $disk = Storage::disk('local');
        $filePath = $disk->path($epg->file_path);
        $downloadPath = "{$epg->file_path}.download";

        // Ensure directory exists
        if (! $disk->exists($epg->folder_path)) {
            $disk->makeDirectory($epg->folder_path);
        }

        // Drop a leftover from an interrupted sync, then open the file for writing
        $disk->delete($downloadPath);
        $file = fopen($disk->path($downloadPath), 'w');
        if (! $file) {
            throw new Exception("Cannot open file for writing: {$disk->path($downloadPath)}");
        }
        $completed = false;
        try {
            // Extract station artwork from lineup data (logos are included in lineup response)
            Log::debug('Extracting station artwork from lineup data');
            $stationArtworkCache = $this->extractStationArtworkFromLineup($lineupData);

            // Write XML header and channels with artwork
            $this->writeXMLTVHeader($file, $lineupData, ['stations' => $stationArtworkCache]);

            // Single-pass processing; program artwork is fetched alongside programs
            $this->runStreamSchedulesToXMLTV($file, $epg, $cache, $stationIds, $dates);

            // Write XML footer
            fwrite($file, "</tv>\n");
            $completed = true;
        } catch (Exception $e) {
            Log::error('Failed to stream process to XMLTV', [
                'epg_id' => $epg->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        } finally {
            fclose($file);
            // Drop a sync that never became the guide file
            if (! $completed) {
                $disk->delete($downloadPath);
            }
            $epg->update(['sd_progress' => 100]);
        }

        if (! $disk->move($downloadPath, $epg->file_path)) {
            throw new Exception("Cannot replace the EPG file: {$filePath}");
        }

        return $filePath;
    }

    /**
     * Write XMLTV header and channel information
     */
    private function writeXMLTVHeader($file, array $lineupData, array $artworkCache = []): void
    {
        fwrite($file, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
        fwrite($file, "<tv generator-info-name=\"m3u editor SchedulesDirect Integration\" generator-info-url=\"https://github.com/sparkison/m3u-editor\">\n");

        // Write channels
        $stationsById = [];
        foreach ($lineupData['stations'] as $station) {
            $stationsById[$station['stationID']] = $station;
        }

        foreach ($lineupData['map'] as $mapping) {
            $station = $stationsById[$mapping['stationID']] ?? null;
            if (! $station) {
                continue;
            }

            fwrite($file, "  <channel id=\"{$mapping['stationID']}\">\n");

            // Display names - prefer name, then callsign, then channel number
            if (! empty($station['name'])) {
                $name = htmlspecialchars($station['name']);
                fwrite($file, "    <display-name>{$name}</display-name>\n");
            }

            if (! empty($station['callsign'])) {
                $callsign = htmlspecialchars($station['callsign']);
                fwrite($file, "    <display-name>{$callsign}</display-name>\n");
            }

            // Channel number and callsign
            $channelNumber = htmlspecialchars($mapping['channel'] ?? $station['callsign']);
            fwrite($file, "    <display-name>{$channelNumber}</display-name>\n");

            // Add channel icon if available
            if (isset($artworkCache['stations'][$mapping['stationID']])) {
                $iconUrl = htmlspecialchars($artworkCache['stations'][$mapping['stationID']]);
                fwrite($file, "    <icon src=\"{$iconUrl}\" />\n");
            }

            fwrite($file, "  </channel>\n");
        }
    }

    /**
     * Simplified single-pass schedule and program processing - no indexing, direct API calls
     */
    private function runStreamSchedulesToXMLTV(
        $file,
        Epg $epg,
        SchedulesDirectCacheStore $cache,
        array $stationIds,
        array $dates
    ): void {
        @ini_set('max_execution_time', 0);

        $totalStations = count($stationIds);
        $totalNetworkChunks = (int) ceil($totalStations / self::STATIONS_PER_CHUNK);
        $totalProgressSteps = $totalNetworkChunks * self::PROGRESS_STEPS_PER_BATCH;

        Log::debug('Starting simplified EPG processing', [
            'epg_id' => $epg->id,
            'total_stations' => $totalStations,
            'schedules_request_chunk_size' => self::STATIONS_PER_CHUNK,
            'progress_steps_per_batch' => self::PROGRESS_STEPS_PER_BATCH,
            'total_progress_steps' => $totalProgressSteps,
        ]);

        // Process schedules and programs in a single streaming pass. Schedules are
        // fetched from SchedulesDirect in large STATIONS_PER_CHUNK batches (fewer
        // requests), then each batch is split into a fixed COUNT of sub-groups
        // (PROGRESS_STEPS_PER_BATCH) - not a fixed entry size - so /programs requests
        // and sd_progress updates stay bounded regardless of sd_days_to_import.
        $progressStep = 0;
        $totalProgramsWritten = 0;
        $totalProgramIds = 0;
        // Programs requested from /programs during this sync, so a program carried
        // on stations in different sub-chunks is never requested twice.
        $requestedProgramIds = [];
        foreach ($this->processScheduleChunks($epg, $cache, $stationIds, $dates) as $scheduleChunk) {
            $subChunkSize = max(1, (int) ceil(count($scheduleChunk) / self::PROGRESS_STEPS_PER_BATCH));
            foreach (array_chunk($scheduleChunk, $subChunkSize) as $scheduleSubChunk) {
                $progressStep++;
                Log::debug('Processing schedule sub-chunk', [
                    'step' => $progressStep,
                    'total_steps' => $totalProgressSteps,
                ]);

                // The MD5 each schedule lists for its programs, and every airing of each program
                $programMd5s = [];
                $airings = [];
                foreach ($scheduleSubChunk as $schedule) {
                    $stationId = $schedule['stationID'] ?? null;
                    if (! $stationId) {
                        continue;
                    }
                    foreach ($schedule['programs'] ?? [] as $scheduleProgram) {
                        $programId = $scheduleProgram['programID'] ?? null;
                        if (! $programId) {
                            continue;
                        }
                        $programMd5s[$programId] = (string) ($scheduleProgram['md5'] ?? '');
                        $airings[$programId][] = [(string) $stationId, $scheduleProgram];
                    }
                }
                $totalProgramIds += count($programMd5s);

                if ($programMd5s !== []) {
                    try {
                        $this->refreshProgramCache($epg, $cache, $programMd5s, $requestedProgramIds);
                        if (self::$FETCH_PROGRAM_ARTWORK) {
                            $this->refreshProgramArtwork($epg, $cache, array_keys($programMd5s));
                        }
                        $chunkProgramsWritten = $this->writeCachedPrograms($epg, $cache, $airings, $file);
                        $totalProgramsWritten += $chunkProgramsWritten;
                        Log::debug('Sub-chunk completed', [
                            'step' => $progressStep,
                            'unique_program_ids' => count($programMd5s),
                            'programs_written' => $chunkProgramsWritten,
                            'total_programs_written' => $totalProgramsWritten,
                        ]);
                    } catch (SchedulesDirectTokenExpiredException|SchedulesDirectServerException $e) {
                        throw $e;
                    } catch (Exception $e) {
                        Log::error('Error processing sub-chunk programs', [
                            'step' => $progressStep,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Update progress
                $progress = min(100, (int) (($progressStep / $totalProgressSteps) * 100));
                $epg->update(['sd_progress' => $progress]);

                // Clear sub-chunk from memory
                unset($scheduleSubChunk, $programMd5s, $airings);

                // Force garbage collection every few sub-chunks
                if ($progressStep % 2 === 0) {
                    gc_collect_cycles();
                }
            }

            unset($scheduleChunk);
        }
        Log::debug('EPG processing completed', [
            'total_programs_written' => $totalProgramsWritten,
            'programs_requested' => count($requestedProgramIds),
            'steps_processed' => $progressStep,
        ]);

        // Schedules listed programmes but none could be fetched: replacing the
        // guide would leave every channel empty while the sync reports success.
        if ($totalProgramIds > 0 && $totalProgramsWritten === 0) {
            throw new Exception('SchedulesDirect returned no programmes; keeping the existing EPG data');
        }

        if ($dates !== []) {
            $cache->prune($dates[0], $stationIds, $epg->sd_lineup_ids ?? []);
        }
    }

    /**
     * Download the programs whose cached copy is missing or whose MD5 differs
     * from the one the schedule lists, and store them in the program cache.
     *
     * @param  array<string, string>  $programMd5s  MD5 keyed by programID
     * @param  array<string, true>  $requestedProgramIds  programs already requested this sync
     */
    private function refreshProgramCache(Epg $epg, SchedulesDirectCacheStore $cache, array $programMd5s, array &$requestedProgramIds): void
    {
        $staleProgramIds = [];
        $cachedMd5s = $cache->programMd5s(array_map('strval', array_keys($programMd5s)));
        foreach ($programMd5s as $programId => $md5) {
            if (($cachedMd5s[$programId] ?? null) !== $md5 && ! isset($requestedProgramIds[$programId])) {
                $staleProgramIds[] = (string) $programId;
                $requestedProgramIds[$programId] = true;
            }
        }

        Log::debug('SchedulesDirect program MD5 comparison', [
            'epg_id' => $epg->id,
            'programs' => count($programMd5s),
            'programs_to_download' => count($staleProgramIds),
        ]);

        foreach (array_chunk($staleProgramIds, self::PROGRAMS_BATCH_SIZE) as $batchIndex => $programBatch) {
            if ($batchIndex > 0) {
                usleep(100000); // 100ms between batches
            }
            $this->cacheProgramBatch($epg, $cache, $programBatch, $batchIndex);
        }
    }

    /**
     * POST a batch of programIDs to /programs, streaming the response to disk,
     * and store each returned program in the program cache.
     *
     * Programs are cached under the MD5 in the /programs response, which
     * SchedulesDirect says is authoritative. Programs and schedules are not
     * updated in lock-step: when a program changes its schedules are rebuilt
     * afterwards, so until then a schedule can list an older MD5 and the program
     * downloads again; that usually settles by the next sync.
     *
     * @param  array<int, string>  $programBatch
     */
    private function cacheProgramBatch(Epg $epg, SchedulesDirectCacheStore $cache, array $programBatch, int $batchIndex): void
    {
        $token = $epg->sd_token;
        // Create a temporary file for the API response
        $tempResponseFile = tempnam(sys_get_temp_dir(), 'epg_programs_response_');
        try {
            Log::debug('Fetching program batch', [
                'batch' => $batchIndex + 1,
                'batch_size' => count($programBatch),
            ]);

            // Stream the API response directly to a file
            $response = Http::withHeaders($this->buildHeaders($token))->timeout(300)->sink($tempResponseFile)->post(self::BASE_URL.'/'.self::API_VERSION.'/programs', $programBatch);
            $errorData = $this->sinkedErrorBody($response, $tempResponseFile);

            // Check for error code 2055 in the response file (API returns error as JSON even on failure)
            if (($errorData['code'] ?? null) === self::DEBUG_NOT_ENABLED_CODE) {
                $this->handleDebugNotEnabledError();

                // Retry the request without the debug header
                Log::debug('Retrying program batch request without debug header');
                $response = Http::withHeaders($this->buildHeaders($token))->timeout(300)->sink($tempResponseFile)->post(self::BASE_URL.'/'.self::API_VERSION.'/programs', $programBatch);
                $errorData = $this->sinkedErrorBody($response, $tempResponseFile);
            }

            if (($errorData['code'] ?? null) === self::TOKEN_EXPIRED_CODE) {
                $this->throwTokenExpired($token, $errorData, '/programs');
            }

            if ($response->serverError()) {
                throw new SchedulesDirectServerException("SchedulesDirect /programs request failed with HTTP {$response->status()}", $response->status());
            }

            if (! $response->successful()) {
                Log::error('Failed to fetch program batch', [
                    'batch' => $batchIndex + 1,
                    'status' => $response->status(),
                ]);

                return;
            }

            $rows = [];
            $cachedCount = 0;
            foreach (Items::fromFile($tempResponseFile) as $program) {
                $programId = $program->programID ?? null;
                // Entries with a non-zero code (e.g. 6001 program queued) carry no program data
                if (! $programId || (int) ($program->code ?? 0) !== 0) {
                    continue;
                }
                $rows[] = [
                    'program_id' => (string) $programId,
                    'md5' => (string) ($program->md5 ?? ''),
                    'data' => array_intersect_key((array) $program, array_flip(self::CACHED_PROGRAM_FIELDS)),
                ];
                if (count($rows) >= 500) {
                    $cache->putPrograms($rows);
                    $cachedCount += count($rows);
                    $rows = [];
                }
            }
            $cache->putPrograms($rows);
            $cachedCount += count($rows);

            Log::debug('Program batch cached', [
                'batch' => $batchIndex + 1,
                'programs_requested' => count($programBatch),
                'programs_cached' => $cachedCount,
            ]);
        } catch (SchedulesDirectTokenExpiredException|SchedulesDirectServerException $e) {
            throw $e;
        } catch (ConnectionException $e) {
            throw new SchedulesDirectServerException("SchedulesDirect /programs request failed: {$e->getMessage()}", 0, $e);
        } catch (Exception $e) {
            Log::error('Error processing program batch', [
                'batch' => $batchIndex + 1,
                'error' => $e->getMessage(),
            ]);
        } finally {
            // Clean up temporary response file
            if (file_exists($tempResponseFile)) {
                unlink($tempResponseFile);
            }
        }
    }

    /**
     * Fetch artwork for the cached programs that have none yet, or whose
     * artwork is older than ARTWORK_REFRESH_DAYS, and store it with them.
     *
     * @param  array<int, string>  $programIds
     */
    private function refreshProgramArtwork(Epg $epg, SchedulesDirectCacheStore $cache, array $programIds): void
    {
        $needsArtwork = $cache->programsNeedingArtwork(
            array_map('strval', $programIds),
            now()->subDays(self::ARTWORK_REFRESH_DAYS)->getTimestamp(),
            now()->subHours(self::MISSING_ARTWORK_RETRY_HOURS)->getTimestamp(),
        );

        foreach (array_chunk($needsArtwork, self::PROGRAMS_BATCH_SIZE) as $batch) {
            $failedProgramIds = [];
            $artwork = $this->getProgramArtwork($epg->sd_token, $batch, $epg->uuid, $failedProgramIds);
            // Failed requests stay unstamped so the next sync tries them again
            $fetchedProgramIds = array_values(array_diff($batch, $failedProgramIds));
            if ($fetchedProgramIds !== []) {
                // Store the image URI only: writeCachedPrograms() rebuilds the URL from it
                $artwork = array_map(
                    fn (array $images) => array_map(fn (array $image) => Arr::except($image, 'url'), $images),
                    $artwork,
                );
                $cache->putArtwork($fetchedProgramIds, $artwork, now()->getTimestamp());
            }
        }
    }

    /**
     * Write every airing of the given programs from the program cache to the
     * XMLTV file, and mark the cached programs as used by this sync.
     *
     * @param  array<string, array<int, array{0: string, 1: array<string, mixed>}>>  $airings  airings keyed by programID
     * @return int the number of programmes written
     */
    private function writeCachedPrograms(Epg $epg, SchedulesDirectCacheStore $cache, array $airings, $file): int
    {
        $written = 0;
        foreach (array_chunk(array_map('strval', array_keys($airings)), self::PROGRAM_CACHE_QUERY_SIZE) as $programIds) {
            foreach ($cache->usePrograms($programIds) as $row) {
                // Decoded as objects, the shape writeProgramToXMLTV() reads
                $program = json_decode($row['data']);
                if (! is_object($program)) {
                    continue;
                }

                $artworkCache = [];
                if (self::$FETCH_PROGRAM_ARTWORK && $row['artwork']) {
                    $artworkCache['programs'][$row['program_id']] = $this->withCurrentImageUrls(json_decode($row['artwork'], true) ?: [], $epg->uuid);
                }

                foreach ($airings[$row['program_id']] ?? [] as [$stationId, $scheduleProgram]) {
                    $this->writeProgramToXMLTV($file, $stationId, $scheduleProgram, $program, $artworkCache);
                    $written++;
                }
            }
        }

        return $written;
    }

    /**
     * Rebuild the URLs of cached artwork from each image's URI, so a change to
     * the proxy base URL applies without fetching the artwork again.
     *
     * @param  array<int, array<string, mixed>>  $artwork
     * @return array<int, array<string, mixed>>
     */
    private function withCurrentImageUrls(array $artwork, ?string $epgUuid): array
    {
        return array_map(fn (array $image) => isset($image['uri'])
            ? [...$image, 'url' => $this->buildImageUrl($image['uri'], $epgUuid, $image['last_update'] ?? null)]
            : $image, $artwork);
    }

    /**
     * Whether a failed request is worth repeating: a timeout or connection error,
     * or a 5xx response. A 4xx response carries an SD error code that a repeat
     * cannot change.
     */
    private static function isTransientFailure(Throwable $exception): bool
    {
        return ! $exception instanceof RequestException || $exception->response->serverError();
    }

    /**
     * The decoded JSON error body of a failed request whose body was streamed to
     * $sinkFile, or an empty array for a successful or non-JSON response.
     *
     * @return array<string, mixed>
     */
    private function sinkedErrorBody(Response $response, string $sinkFile): array
    {
        if ($response->successful() || ! file_exists($sinkFile)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($sinkFile), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Write a single program to XMLTV file working directly with JsonMachine objects
     */
    private function writeProgramToXMLTV($file, string $stationId, array $scheduleProgram, $programData, array $artworkCache = []): void
    {
        // Handle schedule program data (always array)
        $airDateTime = $scheduleProgram['airDateTime'];
        $duration = $scheduleProgram['duration'];
        $isNew = $scheduleProgram['new'] ?? false;
        $start = Carbon::parse($airDateTime)->format('YmdHis O');
        $stop = Carbon::parse($airDateTime)->addSeconds($duration)->format('YmdHis O');

        // Start programme entry
        fwrite($file, "  <programme channel=\"{$stationId}\" start=\"{$start}\" stop=\"{$stop}\">\n");

        // Title - work directly with JsonMachine object
        if (! empty($programData->titles[0]->title120)) {
            $title = htmlspecialchars($programData->titles[0]->title120);
            fwrite($file, "    <title>{$title}</title>\n");
        }

        // Episode title
        if (! empty($programData->episodeTitle150)) {
            $subTitle = htmlspecialchars($programData->episodeTitle150);
            fwrite($file, "    <sub-title>{$subTitle}</sub-title>\n");
        }

        // Description
        if (! empty($programData->descriptions->description1000[0]->description)) {
            $desc = htmlspecialchars($programData->descriptions->description1000[0]->description);
            fwrite($file, "    <desc>{$desc}</desc>\n");
        }

        // Program artwork using proper XMLTV <image> tags
        $programId = $programData->programID ?? null;
        if ($programId && isset($artworkCache['programs'][$programId])) {
            $artworkList = $artworkCache['programs'][$programId];
            if (is_array($artworkList)) {
                // New format - multiple images with proper XMLTV attributes
                foreach ($artworkList as $artwork) {
                    $url = htmlspecialchars($artwork['url']);
                    $type = htmlspecialchars($artwork['type']);
                    $size = htmlspecialchars($artwork['size']);
                    $orient = htmlspecialchars($artwork['orient']);
                    $width = (int) ($artwork['width'] ?? 0);
                    $height = (int) ($artwork['height'] ?? 0);
                    fwrite($file, "    <icon src=\"{$url}\" type=\"{$type}\" width=\"{$width}\" height=\"{$height}\" orient=\"{$orient}\" size=\"{$size}\" />\n");
                }
            }
        }

        // Categories/Genres
        if (! empty($programData->genres)) {
            foreach ($programData->genres as $genre) {
                $genre = htmlspecialchars($genre);
                fwrite($file, "    <category>{$genre}</category>\n");
            }
        }

        // Episode numbering
        if (! empty($programData->metadata)) {
            foreach ($programData->metadata as $metadata) {
                if (isset($metadata->Gracenote->season) && isset($metadata->Gracenote->episode)) {
                    $season = max(0, $metadata->Gracenote->season - 1);
                    $episode = max(0, $metadata->Gracenote->episode - 1);
                    fwrite($file, "    <episode-num system=\"xmltv_ns\">{$season}.{$episode}.</episode-num>\n");
                    break;
                }
            }
        }

        // Content rating
        if (! empty($programData->contentRating)) {
            foreach ($programData->contentRating as $rating) {
                if ($rating->country === 'USA') {
                    $ratingSystem = htmlspecialchars($rating->body);
                    $ratingValue = htmlspecialchars($rating->code);
                    fwrite($file, "    <rating system=\"{$ratingSystem}\"><value>{$ratingValue}</value></rating>\n");
                    break;
                }
            }
        }

        // New flag
        if (! empty($isNew)) {
            fwrite($file, "    <new />\n");
        }

        // End programme entry
        fwrite($file, "  </programme>\n");
    }

    /**
     * Make authenticated request to SchedulesDirect API with improved error handling
     */
    private function makeRequest(string $method, string $endpoint, array $data = [], ?string $token = null): Response
    {
        $headers = $this->buildHeaders($token);
        $url = self::BASE_URL.'/'.self::API_VERSION.$endpoint;

        // Configure timeout based on endpoint and data size
        $timeout = self::DEFAULT_TIMEOUT;
        if (str_contains($endpoint, '/schedules/md5')) {
            $timeout = 45; // Hash requests are faster but still need time
        } elseif (str_contains($endpoint, '/schedules')) {
            $timeout = self::SCHEDULES_TIMEOUT;
        } elseif (str_contains($endpoint, '/programs')) {
            $dataSize = is_array($data) ? count($data) : 0;
            // Scale timeout based on data size for program requests
            if ($dataSize > 1000) {
                $timeout = 300; // 5 minutes for very large batches
            } elseif ($dataSize > 500) {
                $timeout = 180; // 3 minutes for large batches
            } else {
                $timeout = 90; // 1.5 minutes for medium batches
            }
        }

        $request = Http::withHeaders($headers)
            ->timeout($timeout)
            // Basic retry with 1 second delay for timeouts and 5xx only: a 4xx carries an SD
            // error code that repeating the request cannot change. A failed response is returned
            // (not thrown) so that code reaches the error handling below instead of being lost.
            ->retry(2, 1000, self::isTransientFailure(...), throw: false)
            ->withOptions([
                'verify' => true,
                'stream' => false, // Disable streaming to prevent memory issues
                'max_redirects' => 3,
                'allow_redirects' => ['strict' => true],
            ]);

        Log::debug('Making SchedulesDirect API request', [
            'method' => $method,
            'endpoint' => $endpoint,
            'timeout' => $timeout,
            'data_size' => is_array($data) ? count($data) : 0,
            'has_token' => ! empty($token),
        ]);
        try {
            $startTime = microtime(true);
            if ($method === 'GET' && ! empty($data)) {
                $url .= '?'.http_build_query($data);
                $response = $request->get($url);
            } elseif ($method === 'POST') {
                $response = $request->post($url, $data);
            } elseif ($method === 'PUT') {
                $response = $request->put($url, $data);
            } else {
                $response = $request->send($method, $url, ['json' => $data]);
            }
            $duration = round(microtime(true) - $startTime, 2);
            Log::debug('SchedulesDirect API request completed', [
                'method' => $method,
                'endpoint' => $endpoint,
                'duration_seconds' => $duration,
                'status_code' => $response->status(),
                'response_size' => strlen($response->body()),
            ]);
        } catch (Exception $e) {
            Log::error('SchedulesDirect API request failed', [
                'method' => $method,
                'endpoint' => $endpoint,
                'timeout' => $timeout,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);
            throw new SchedulesDirectServerException("SchedulesDirect API request failed: {$e->getMessage()}", 0, $e);
        }
        // Check response body for error codes (API may return error codes in successful HTTP responses)
        $body = $response->json();
        $responseCode = $body['code'] ?? null;

        // Handle code 2055: debug not enabled - disable sd_debug and retry without debug header
        if ($responseCode === self::DEBUG_NOT_ENABLED_CODE) {
            $this->handleDebugNotEnabledError();

            // Retry the request without the debug header
            Log::debug('Retrying request without debug header', [
                'method' => $method,
                'endpoint' => $endpoint,
            ]);

            $headers = $this->buildHeaders($token);
            $request = Http::withHeaders($headers)
                ->timeout($timeout)
                ->retry(2, 1000, self::isTransientFailure(...), throw: false)
                ->withOptions([
                    'verify' => true,
                    'stream' => false,
                    'max_redirects' => 3,
                    'allow_redirects' => ['strict' => true],
                ]);

            if ($method === 'GET' && ! empty($data)) {
                $response = $request->get($url);
            } elseif ($method === 'POST') {
                $response = $request->post($url, $data);
            } elseif ($method === 'PUT') {
                $response = $request->put($url, $data);
            } else {
                $response = $request->send($method, $url, ['json' => $data]);
            }

            $body = $response->json();
            $responseCode = $body['code'] ?? null;
        }

        // Token expired mid-request (code 4006): discard the stored token so the
        // next attempt re-authenticates, and let the caller perform exactly one
        // controlled retry. Checked before the generic failure branch because
        // Schedules Direct may return this on either an HTTP 4xx or a 200.
        if ($responseCode === self::TOKEN_EXPIRED_CODE) {
            $this->throwTokenExpired($token, is_array($body) ? $body : [], $endpoint);
        }

        // SD can report errors in an HTTP 200 body, so a non-zero code or a non-JSON body is a failure too
        $invalidBody = ! is_array($body);
        if ($response->failed() || $invalidBody || (is_numeric($responseCode) && (int) $responseCode !== 0)) {
            $message = $invalidBody ? 'Invalid response body' : ($body['message'] ?? $body['response'] ?? 'Unknown error');
            $code = $responseCode ?? $response->status();

            Log::error('SchedulesDirect API error response', [
                'method' => $method,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'code' => $code,
                'message' => $message,
                'full_response' => $response->body(),
            ]);
            if ($response->serverError() || (is_array($body) && $this->isServiceOffline($body))) {
                throw new SchedulesDirectServerException("SchedulesDirect API error: {$message} (Code: {$code})", (int) $code);
            }
            throw new Exception("SchedulesDirect API error: {$message} (Code: {$code})", (int) $code);
        }

        return $response;
    }
}
