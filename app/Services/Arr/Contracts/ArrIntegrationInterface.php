<?php

namespace App\Services\Arr\Contracts;

use App\Models\ArrIntegration;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;

interface ArrIntegrationInterface
{
    /**
     * Test connection to the Sonarr/Radarr server.
     *
     * @return array{ok: bool, version?: string, error?: string}
     */
    public function testConnection(): array;

    /**
     * Fetch available quality profiles.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function fetchQualityProfiles(): array;

    /**
     * Fetch available root folders.
     *
     * @return array<int, array{id: int, path: string, freeSpace?: int}>
     */
    public function fetchRootFolders(): array;

    /**
     * Search the catalogue for a term.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $term): array;

    /**
     * The /lookup endpoint path for this integration (e.g. /movie/lookup or /series/lookup).
     * Used by ArrSearch to build parallel pool requests.
     */
    public function getSearchEndpoint(): string;

    /**
     * Parse a raw /lookup HTTP response into the normalized search result shape.
     * Separated from search() to allow parallel fetching via Http::pool() in ArrSearch.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parseSearchResponse(Response $response): array;

    /**
     * Add content to the library and trigger a search.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, data?: array<string, mixed>, error?: string}
     */
    public function add(array $payload): array;

    /**
     * Check whether an external ID already exists in the library.
     *
     * @return array{exists: bool, id?: int}
     */
    public function checkExists(int $externalId): array;

    /**
     * Fetch interactive search releases for content already in the library.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchReleases(int $contentId): array;

    /**
     * Trigger a download of a specific release.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, error?: string}
     */
    public function downloadRelease(array $payload): array;

    /**
     * Trigger an automatic search for content already in the library.
     *
     * @return array{ok: bool, data?: int, error?: string}
     */
    public function triggerAutomaticSearch(int $contentId): array;

    /**
     * Get the status of a command by its ID ('queued', 'started', 'completed', 'failed', etc.).
     * Returns 'completed' if the command has aged out (404).
     */
    public function getCommandStatus(int $commandId): string;

    /**
     * Count history records of type 'grabbed' for the given content since a timestamp.
     */
    public function fetchRecentGrabCount(int $contentId, Carbon $since): int;

    /**
     * Fetch the current download queue.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchQueue(): array;

    /**
     * The integration this service was constructed for.
     */
    public function getIntegration(): ArrIntegration;

    /**
     * Fetch all TMDB IDs currently in the library (for library cross-reference).
     *
     * @return array<int>
     */
    public function fetchLibraryTmdbIds(): array;

    /**
     * Whether this integration supports episode-level operations (Sonarr only).
     * Use this instead of instanceof checks to keep components decoupled from concrete types.
     */
    public function supportsEpisodes(): bool;

    /**
     * Parse raw /queue API records into the normalized queue item shape.
     * Separated from fetchQueue() to allow parallel HTTP fetching via Http::pool().
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    public function parseQueueRecords(array $records): array;
}
