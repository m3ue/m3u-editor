<?php

namespace App\Services;

use App\Exceptions\SchedulesDirectRateLimitException;
use App\Exceptions\SchedulesDirectTokenExpiredException;
use App\Facades\ProxyFacade;
use App\Models\Epg;
use Carbon\Carbon;
use Exception;
use Generator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonMachine\Items;

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

    public const IMAGE_NOT_FOUND_CODE = 4001;

    /** Trial account download limit exceeded. */
    public const EXCEED_DOWNLOAD_LIMIT_TRIAL_CODE = 4003;

    /** Full subscriber download limit exceeded. */
    public const EXCEED_DOWNLOAD_LIMIT_CODE = 4004;

    /** Token has expired; the stored token must be discarded and re-requested once. */
    public const TOKEN_EXPIRED_CODE = 4006;

    /** Exceeded the maximum number of logins in 24 hours. */
    public const TOO_MANY_LOGINS_CODE = 4009;

    /**
     * Safety margin subtracted from the provider's real token expiry when persisting
     * it, so a request never goes out with a token that expires in flight.
     */
    private const TOKEN_EXPIRY_SKEW_SECONDS = 60;

    /** Length of the cooldown entered after a TOO_MANY_LOGINS (4009) rejection. */
    private const LOGIN_COOLDOWN_HOURS = 24;

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
    // is streamed straight to disk (see processProgramBatchDirectly's sink()), so there's
    // no memory cost to requesting the maximum and cutting the number of requests by 5x.
    private const PROGRAMS_BATCH_SIZE = 5000;

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
    private function processScheduleChunks(string $token, array $stationIds, array $dates): Generator
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

                $stationRequests = array_map(function ($stationId) use ($dates) {
                    return [
                        'stationID' => $stationId,
                        'date' => $dates,
                    ];
                }, $stationChunk);

                try {
                    $chunkSchedules = $this->getSchedules($token, $stationRequests);

                    if (is_array($chunkSchedules) && ! empty($chunkSchedules)) {
                        $success = true;
                        yield $chunkSchedules;

                        // Clear the chunk data immediately
                        unset($chunkSchedules, $stationRequests);
                    } else {
                        throw new Exception('Empty or invalid response received');
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
     * Resolve the canonical token expiry from a /token response.
     *
     * Schedules Direct returns `tokenExpires` as the UNIX epoch at which the
     * token stops working. `datetime` / `serverTime` are the server's current
     * clock and must never be treated as an expiry. When `tokenExpires` is
     * absent we fall back to the documented 24-hour lifetime. A small skew
     * margin is subtracted so a request never leaves with a token that lapses
     * in flight.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveTokenExpiry(array $data): Carbon
    {
        $tokenExpires = $data['tokenExpires'] ?? null;

        // Carbon 3 returns a UTC instance from a bare timestamp; pin it to the
        // app timezone so it round-trips through the tz-naive datetime column.
        $expiresAt = is_numeric($tokenExpires)
            ? Carbon::createFromTimestamp((int) $tokenExpires, config('app.timezone'))
            : now()->addDay();

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

        $response = Http::withHeaders($headers)->post(self::BASE_URL.'/'.self::API_VERSION.'/token', $payload);
        $data = $response->json() ?? [];

        if (($data['code'] ?? null) === self::DEBUG_NOT_ENABLED_CODE) {
            $this->handleDebugNotEnabledError();

            Log::debug('Retrying authentication without debug header');
            $response = Http::withHeaders(['User-Agent' => self::$USER_AGENT])
                ->post(self::BASE_URL.'/'.self::API_VERSION.'/token', $payload);
            $data = $response->json() ?? [];
        }

        // Fold the HTTP status into the body so callers only inspect one place.
        // Schedules Direct returns 4009 with an HTTP 4xx and a `response` string
        // rather than a `message`, so normalize that here.
        if ($response->failed() && ! isset($data['code'])) {
            throw new Exception('Authentication failed: '.$response->body());
        }

        return $data;
    }

    /**
     * Authenticate with SchedulesDirect using raw credentials (no EPG persistence).
     *
     * @return array{token: string, expires: int}
     */
    public function authenticate(string $username, string $password): array
    {
        $data = $this->postToken($username, hash('sha1', $password), withEpgHeaders: false);

        if ($this->isTooManyLogins($data)) {
            throw new SchedulesDirectRateLimitException(now()->addHours(self::LOGIN_COOLDOWN_HOURS));
        }

        if (isset($data['code']) && $data['code'] !== 0) {
            throw new Exception('Authentication error: '.($data['message'] ?? $data['response'] ?? 'Unknown error'));
        }

        return [
            'token' => $data['token'],
            'expires' => $this->resolveTokenExpiry($data)->getTimestamp(),
        ];
    }

    /**
     * Authenticate using an EPG model with stored credentials.
     *
     * Authentication is single-flight per Schedules Direct account: only one
     * worker may hold the per-account lock and issue a /token request at a time.
     * Workers that arrive while a peer is authenticating wait for the lock, then
     * reuse the token the peer stored instead of logging in again.
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

        if ($epg->isInSchedulesDirectLoginCooldown()) {
            throw new SchedulesDirectRateLimitException($epg->sd_login_cooldown_until);
        }

        // Namespacing hash only, not a security boundary: keeps the lock key
        // stable and free of characters the cache store might choke on.
        $lock = Cache::lock('sd-auth:'.hash('sha256', (string) $epg->sd_username), self::AUTH_LOCK_SECONDS);

        try {
            $lock->block(self::AUTH_LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            // A peer is authenticating and is taking longer than our patience.
            // Reuse whatever it has stored by now, otherwise give up for this run.
            $epg->refresh();
            if ($epg->hasValidSchedulesDirectToken()) {
                return ['token' => $epg->sd_token, 'expires' => $epg->sd_token_expires_at->getTimestamp()];
            }
            if ($epg->isInSchedulesDirectLoginCooldown()) {
                throw new SchedulesDirectRateLimitException($epg->sd_login_cooldown_until);
            }
            throw new Exception('Timed out waiting for a concurrent SchedulesDirect authentication');
        }

        try {
            // Re-check under the lock: a peer may have just refreshed the token
            // or tripped the cooldown while we were waiting.
            $epg->refresh();
            if ($epg->hasValidSchedulesDirectToken()) {
                return ['token' => $epg->sd_token, 'expires' => $epg->sd_token_expires_at->getTimestamp()];
            }
            if ($epg->isInSchedulesDirectLoginCooldown()) {
                throw new SchedulesDirectRateLimitException($epg->sd_login_cooldown_until);
            }

            $data = $this->postToken($epg->sd_username, hash('sha1', $epg->sd_password), withEpgHeaders: true);

            if ($this->isTooManyLogins($data)) {
                $retryAt = $this->beginLoginCooldown($epg);
                throw new SchedulesDirectRateLimitException($retryAt);
            }

            if (isset($data['code']) && $data['code'] !== 0) {
                throw new Exception('Authentication error: '.($data['message'] ?? $data['response'] ?? 'Unknown error'));
            }

            $expiresAt = $this->resolveTokenExpiry($data);

            $epg->update([
                'sd_token' => $data['token'],
                'sd_token_expires_at' => $expiresAt,
                // A successful login clears any prior rate-limit state.
                'sd_login_cooldown_until' => null,
            ]);

            return [
                'token' => $data['token'],
                'expires' => $expiresAt->getTimestamp(),
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isTooManyLogins(array $data): bool
    {
        return ($data['code'] ?? null) === self::TOO_MANY_LOGINS_CODE
            || ($data['response'] ?? null) === 'TOO_MANY_LOGINS';
    }

    /**
     * Enter a bounded cooldown after a 4009 rejection. An already-active cooldown
     * is never pushed forward, so attempts that are locally suppressed during the
     * window cannot extend it.
     */
    private function beginLoginCooldown(Epg $epg): Carbon
    {
        if ($epg->isInSchedulesDirectLoginCooldown()) {
            return $epg->sd_login_cooldown_until;
        }

        $retryAt = now()->addHours(self::LOGIN_COOLDOWN_HOURS);
        $epg->update(['sd_login_cooldown_until' => $retryAt]);

        Log::warning('SchedulesDirect login limit reached (4009); entering cooldown', [
            'epg_id' => $epg->id,
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
                self::LINEUP_ALREADY_IN_ACCOUNT_CODE => null, // idempotent — already added
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
                self::LINEUP_NOT_IN_ACCOUNT_CODE => null, // idempotent — already removed
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
            ->mapWithKeys(fn ($lineup) => [$lineup['lineup'] => "{$lineup['name']} — {$lineup['lineup']} ({$lineup['transport']})"])
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
     * Remove the lineup that is currently configured on the EPG from the SD account.
     * No-op if no lineup is configured.
     */
    public function removeConfiguredLineup(Epg $epg): void
    {
        if (! $epg->hasSchedulesDirectLineup()) {
            return;
        }

        $this->removeLineupFromEpg($epg, $epg->sd_lineup_id);
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
     * Get artwork for programs
     *
     * Based on testing, the /metadata/programs endpoint returns error 1008 "INCORRECT_REQUEST"
     * for all tested formats. The regular /programs endpoint shows hasImageArtwork=true,
     * indicating artwork is available, but accessed differently.
     *
     * For now, this returns empty array but could be enhanced to:
     * 1. Check for artwork URLs embedded in program responses
     * 2. Try alternative API endpoints for metadata
     * 3. Use program flags to determine if artwork exists
     */
    public function getProgramArtwork(string $token, array $programIds, ?string $epgUuid = null): array
    {
        if (empty($programIds)) {
            return [];
        }

        // SchedulesDirect has a limit of 500 program IDs per request
        $maxBatchSize = 500;
        $allArtwork = [];

        try {
            Log::debug('Fetching program artwork from SchedulesDirect', [
                'program_count' => count($programIds),
                'batches_needed' => ceil(count($programIds) / $maxBatchSize),
            ]);

            // Process in batches of 500 or fewer
            $batches = array_chunk($programIds, $maxBatchSize);

            foreach ($batches as $batchIndex => $batch) {
                Log::debug('Processing artwork batch', [
                    'batch' => $batchIndex + 1,
                    'batch_size' => count($batch),
                ]);

                // The correct endpoint requires a trailing slash: /metadata/programs/
                $response = Http::withHeaders($this->buildHeaders($token))->timeout(30)->post(self::BASE_URL.'/'.self::API_VERSION.'/metadata/programs/', $batch);

                $artworkData = $response->json();

                // Handle code 2055: debug not enabled - disable sd_debug and retry without debug header
                if (isset($artworkData['code']) && $artworkData['code'] === self::DEBUG_NOT_ENABLED_CODE) {
                    $this->handleDebugNotEnabledError();

                    // Retry the request without the debug header
                    Log::debug('Retrying artwork request without debug header');
                    $response = Http::withHeaders($this->buildHeaders($token))->timeout(30)->post(self::BASE_URL.'/'.self::API_VERSION.'/metadata/programs/', $batch);
                    $artworkData = $response->json();
                }

                if ($response->successful()) {
                    foreach ($artworkData as $programArtwork) {
                        $programId = $programArtwork['programID'] ?? null;
                        $artworkItems = $programArtwork['data'] ?? [];

                        if ($programId && ! empty($artworkItems)) {
                            // Group and process all artwork types, not just the "best" one
                            $processedArtwork = $this->selectBestArtwork($artworkItems, $epgUuid);

                            if (! empty($processedArtwork)) {
                                $allArtwork[$programId] = $processedArtwork;
                            }
                        }
                    }
                } else {
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

            Log::debug('Successfully fetched program artwork', [
                'programs_with_artwork' => count($allArtwork),
                'total_programs' => count($programIds),
                'batches_processed' => count($batches),
            ]);

            return $allArtwork;
        } catch (Exception $e) {
            Log::error('Exception while fetching program artwork', [
                'error' => $e->getMessage(),
                'program_count' => count($programIds),
            ]);

            return [];
        }
    }

    /**
     * Select only the best 1-2 images per type to avoid XMLTV bloat
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
                $imageUrl = $this->buildImageUrl($artwork['uri'], $epgUuid);

                $artworkInfo = [
                    'url' => $imageUrl,
                    'type' => $type,
                    'width' => $artwork['width'] ?? 0,
                    'height' => $artwork['height'] ?? 0,
                    'orient' => $this->determineOrientation($artwork['width'] ?? 0, $artwork['height'] ?? 0),
                    'size' => $this->mapImageSize($artwork['width'] ?? 0, $artwork['height'] ?? 0),
                    'category' => $artwork['category'] ?? '',
                    'tier' => $artwork['tier'] ?? '',
                ];

                $selectedArtwork[] = $artworkInfo;
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
     * Build the complete image URL from the URI
     */
    private function buildImageUrl(string $uri, ?string $epgUuid = null): string
    {
        // If URI is already a complete URL (starts with https://), return as-is
        if (str_starts_with($uri, 'https://')) {
            return $uri;
        }

        // If we have an EPG UUID, use the proxy URL
        if ($epgUuid) {
            return ProxyFacade::getBaseUrl().'/schedules-direct/'.$epgUuid.'/image/'.$uri;
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
     * Extract artwork URL directly from program data
     * This looks for artwork URLs embedded in the program response itself
     */
    private function extractArtworkFromProgram($program): ?string
    {
        // Check if program has artwork flags
        $hasArtwork = $program->hasImageArtwork ?? false;
        $hasEpisodeArtwork = $program->hasEpisodeArtwork ?? false;
        $hasSeriesArtwork = $program->hasSeriesArtwork ?? false;
        $hasSeasonArtwork = $program->hasSeasonArtwork ?? false;

        if (! $hasArtwork && ! $hasEpisodeArtwork && ! $hasSeriesArtwork && ! $hasSeasonArtwork) {
            return null;
        }

        // For now, we'll return null since we need to implement the metadata API correctly
        // The artwork URLs are not included in the regular program data
        return null;
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

                return;
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

            // Check if lineup is already in account; add it if not
            try {
                $lineupData = $this->getLineup($epg->sd_token, $epg->sd_lineup_id);
            } catch (Exception $e) {
                // 4003/4004 = lineup not found/not subscribed
                if ($e->getCode() === self::LINEUP_NOT_IN_ACCOUNT_CODE
                    || str_contains($e->getMessage(), 'not in account')
                    || str_contains($e->getMessage(), 'not subscribed')
                ) {
                    Log::debug("Adding lineup {$epg->sd_lineup_id} to SchedulesDirect account", ['epg_id' => $epg->id]);
                    $this->addLineup($epg->sd_token, $epg->sd_lineup_id);
                    $lineupData = $this->getLineup($epg->sd_token, $epg->sd_lineup_id);
                } else {
                    throw $e;
                }
            }

            // Refresh station IDs from the current lineup on every sync so stations
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
            $xmlFilePath = $this->streamProcessToXMLTV($epg, $lineupData, $stationIds, $dates);

            // Update EPG record
            $epg->update([
                'sd_last_sync' => now(),
                'sd_errors' => null,
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
        }
    }

    /**
     * Stream process data directly to XMLTV file to minimize memory usage
     * Optimized version that processes schedules and programs in a single pass
     */
    private function streamProcessToXMLTV(
        Epg $epg,
        array $lineupData,
        array $stationIds,
        array $dates
    ): string {
        // Prepare file path
        $filePath = Storage::disk('local')->path($epg->file_path);

        // Remove old file if exists
        if (Storage::disk('local')->exists($epg->file_path)) {
            Storage::disk('local')->delete($epg->file_path);
        }

        // Ensure directory exists
        if (! Storage::disk('local')->exists($epg->folder_path)) {
            Storage::disk('local')->makeDirectory($epg->folder_path);
        }

        // Open file for writing
        $file = fopen($filePath, 'w');
        if (! $file) {
            throw new Exception("Cannot open file for writing: {$filePath}");
        }
        try {
            // Extract station artwork from lineup data (logos are included in lineup response)
            Log::debug('Extracting station artwork from lineup data');
            $stationArtworkCache = $this->extractStationArtworkFromLineup($lineupData);

            // Write XML header and channels with artwork
            $this->writeXMLTVHeader($file, $lineupData, ['stations' => $stationArtworkCache]);

            // Use optimized single-pass processing - program artwork will be fetched during processing
            $this->runStreamSchedulesToXMLTV($file, $epg, $stationIds, $dates, ['stations' => $stationArtworkCache]);

            // Write XML footer
            fwrite($file, "</tv>\n");
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
            $epg->update(['sd_progress' => 100]);
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
        array $stationIds,
        array $dates,
        array $artworkCache = []
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
        foreach ($this->processScheduleChunks($epg->sd_token, $stationIds, $dates) as $scheduleChunk) {
            $subChunkSize = max(1, (int) ceil(count($scheduleChunk) / self::PROGRESS_STEPS_PER_BATCH));
            foreach (array_chunk($scheduleChunk, $subChunkSize) as $scheduleSubChunk) {
                $progressStep++;
                Log::debug('Processing schedule sub-chunk', [
                    'step' => $progressStep,
                    'total_steps' => $totalProgressSteps,
                ]);

                // Stream through the sub-chunk and collect unique program IDs using file-based deduplication
                $tempProgramIdFile = tempnam(sys_get_temp_dir(), 'epg_programs_chunk_'.$progressStep.'_');
                $programIdHandle = fopen($tempProgramIdFile, 'w');
                $seenProgramIds = []; // Small lookup table for deduplication
                $scheduleCount = 0;
                $programCount = 0;
                foreach ($scheduleSubChunk as $schedule) {
                    $scheduleCount++;
                    foreach ($schedule['programs'] ?? [] as $program) {
                        $programId = $program['programID'];
                        // Use array key existence check for O(1) deduplication
                        if (! isset($seenProgramIds[$programId])) {
                            $seenProgramIds[$programId] = true;
                            fwrite($programIdHandle, $programId."\n");
                            $programCount++;
                        }
                    }
                }

                // Close the program ID file handle
                fclose($programIdHandle);
                Log::debug('Collected program IDs from schedule sub-chunk', [
                    'step' => $progressStep,
                    'schedules_in_sub_chunk' => $scheduleCount,
                    'unique_program_ids' => $programCount,
                ]);

                // Fetch programs for this sub-chunk only using streaming batches
                if ($programCount > 0) {
                    Log::debug('Fetching programs for sub-chunk', [
                        'step' => $progressStep,
                        'program_count' => $programCount,
                    ]);
                    try {
                        // Stream process programs directly without creating lookup arrays
                        $chunkProgramsWritten = 0;
                        $this->streamProcessProgramsDirectly($tempProgramIdFile, $epg->sd_token, $progressStep, $scheduleSubChunk, $file, $chunkProgramsWritten, $artworkCache, $epg);
                        $totalProgramsWritten += $chunkProgramsWritten;
                        Log::debug('Sub-chunk completed', [
                            'step' => $progressStep,
                            'programs_written' => $chunkProgramsWritten,
                            'total_programs_written' => $totalProgramsWritten,
                        ]);
                    } catch (Exception $e) {
                        Log::error('Error processing sub-chunk programs', [
                            'step' => $progressStep,
                            'error' => $e->getMessage(),
                        ]);

                        continue;
                    } finally {
                        // Clean up temporary file
                        if (isset($tempProgramIdFile) && file_exists($tempProgramIdFile)) {
                            unlink($tempProgramIdFile);
                        }
                    }
                }

                // Update progress
                $progress = min(100, (int) (($progressStep / $totalProgressSteps) * 100));
                $epg->update(['sd_progress' => $progress]);

                // Clear sub-chunk from memory
                unset($scheduleSubChunk, $seenProgramIds);

                // Force garbage collection every few sub-chunks
                if ($progressStep % 2 === 0) {
                    gc_collect_cycles();
                }
            }

            unset($scheduleChunk);
        }
        Log::debug('EPG processing completed', [
            'total_programs_written' => $totalProgramsWritten,
            'steps_processed' => $progressStep,
        ]);
    }

    /**
     * Stream process programs directly without creating lookup arrays - pure streaming approach
     */
    private function streamProcessProgramsDirectly(string $programIdFile, string $token, int $chunkIndex, array $scheduleChunk, $file, int &$programsWritten, array $artworkCache = [], ?Epg $epg = null): void
    {
        $handle = fopen($programIdFile, 'r');
        if (! $handle) {
            throw new Exception("Cannot open program ID file: {$programIdFile}");
        }

        $batch = [];
        $batchIndex = 0;
        try {
            // Stream through program IDs and batch them
            while (($line = fgets($handle)) !== false) {
                $programId = trim($line);
                if (! empty($programId)) {
                    $batch[] = $programId;

                    // When we reach batch size, process the programs immediately
                    if (count($batch) >= self::PROGRAMS_BATCH_SIZE) {
                        $this->processProgramBatchDirectly($batch, $batchIndex, $token, $chunkIndex, $scheduleChunk, $file, $programsWritten, $artworkCache, $epg);
                        $batch = []; // Clear the batch
                        $batchIndex++;

                        // Small delay between batches
                        usleep(100000); // 100ms
                    }
                }
            }

            // Process remaining programs in the last batch
            if (! empty($batch)) {
                $this->processProgramBatchDirectly($batch, $batchIndex, $token, $chunkIndex, $scheduleChunk, $file, $programsWritten, $artworkCache, $epg);
            }
            Log::debug('Completed streaming direct program processing', [
                'chunk' => $chunkIndex,
                'total_batches' => $batchIndex + 1,
                'programs_written' => $programsWritten,
            ]);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Process a batch of programs and immediately write matching schedule entries - no arrays
     */
    private function processProgramBatchDirectly(array $programBatch, int $batchIndex, string $token, int $chunkIndex, array $scheduleChunk, $file, int &$programsWritten, array $artworkCache = [], ?Epg $epg = null): void
    {
        // Create a temporary file for the API response
        $tempResponseFile = tempnam(sys_get_temp_dir(), 'epg_programs_response_');
        try {
            Log::debug('Fetching program batch for direct processing', [
                'chunk' => $chunkIndex,
                'batch' => $batchIndex + 1,
                'batch_size' => count($programBatch),
            ]);

            // Fetch program artwork using the corrected metadata endpoint (if enabled)
            $programArtworkCache = [];
            if (self::$FETCH_PROGRAM_ARTWORK) {
                $programArtworkCache = $this->getProgramArtwork($token, $programBatch, $epg?->uuid);
                Log::debug("Fetched artwork for {$batchIndex} programs", ['artwork_count' => count($programArtworkCache)]);
            } else {
                Log::debug('Program artwork disabled for faster sync');
            }

            // Merge with existing artwork cache
            $fullArtworkCache = array_merge($artworkCache, ['programs' => $programArtworkCache]);

            // Stream the API response directly to a file
            $response = Http::withHeaders($this->buildHeaders($token))->timeout(300)->sink($tempResponseFile)->post(self::BASE_URL.'/'.self::API_VERSION.'/programs', $programBatch);

            // Check for error code 2055 in the response file (API returns error as JSON even on failure)
            if (! $response->successful() && file_exists($tempResponseFile)) {
                $errorContent = file_get_contents($tempResponseFile);
                $errorData = json_decode($errorContent, true);
                if (isset($errorData['code']) && $errorData['code'] === self::DEBUG_NOT_ENABLED_CODE) {
                    $this->handleDebugNotEnabledError();

                    // Retry the request without the debug header
                    Log::debug('Retrying program batch request without debug header');
                    $response = Http::withHeaders($this->buildHeaders($token))->timeout(300)->sink($tempResponseFile)->post(self::BASE_URL.'/'.self::API_VERSION.'/programs', $programBatch);
                }
            }

            if ($response->successful()) {
                // Stream through the program response and match with schedules immediately
                $programs = Items::fromFile($tempResponseFile);
                foreach ($programs as $program) {
                    $programId = $program->programID ?? null;
                    if (! $programId) {
                        continue;
                    }

                    // Extract artwork URLs directly from program data if available
                    $programArtworkUrl = $this->extractArtworkFromProgram($program);
                    if ($programArtworkUrl) {
                        $fullArtworkCache['programs'][$programId] = $programArtworkUrl;
                    }

                    // Find matching schedule entries and write them immediately
                    foreach ($scheduleChunk as $schedule) {
                        $stationId = $schedule['stationID'] ?? null;
                        if (! $stationId) {
                            continue;
                        }

                        foreach ($schedule['programs'] ?? [] as $scheduleProgram) {
                            if ($scheduleProgram['programID'] === $programId) {
                                $this->writeProgramToXMLTV($file, $stationId, $scheduleProgram, $program, $fullArtworkCache);
                                $programsWritten++;
                            }
                        }
                    }
                }

                // Clear the JsonMachine iterator immediately
                unset($programs);
                Log::debug('Program batch processed directly', [
                    'chunk' => $chunkIndex,
                    'batch' => $batchIndex + 1,
                    'programs_in_batch' => count($programBatch),
                    'programs_written_in_batch' => $programsWritten,
                ]);
            } else {
                Log::error('Failed to fetch program batch', [
                    'chunk' => $chunkIndex,
                    'batch' => $batchIndex + 1,
                    'status' => $response->status(),
                ]);
            }
        } catch (Exception $e) {
            Log::error('Error processing program batch directly', [
                'chunk' => $chunkIndex,
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
        if (str_contains($endpoint, '/schedules')) {
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
        } elseif (str_contains($endpoint, '/schedules/md5')) {
            $timeout = 45; // Hash requests are faster but still need time
        }

        $request = Http::withHeaders($headers)
            ->timeout($timeout)
            ->retry(2, 1000) // Basic retry with 1 second delay
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
            throw new Exception("SchedulesDirect API request failed: {$e->getMessage()}");
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
                ->retry(2, 1000)
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
            if ($this->currentEpg) {
                $this->currentEpg->update([
                    'sd_token' => null,
                    'sd_token_expires_at' => null,
                ]);
            }

            Log::warning('SchedulesDirect token expired (4006); cleared stored token', [
                'endpoint' => $endpoint,
                'epg_id' => $this->currentEpg?->id,
            ]);

            throw new SchedulesDirectTokenExpiredException('SchedulesDirect token expired (Code: 4006)');
        }

        if ($response->failed()) {
            $message = $body['message'] ?? $body['response'] ?? 'Unknown error';
            $code = $responseCode ?? $response->status();

            Log::error('SchedulesDirect API error response', [
                'method' => $method,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'code' => $code,
                'message' => $message,
                'full_response' => $response->body(),
            ]);
            throw new Exception("SchedulesDirect API error: {$message} (Code: {$code})", (int) $code);
        }

        return $response;
    }
}
