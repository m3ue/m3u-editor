<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TvMazeService
{
    protected const BASE_URL = 'https://api.tvmaze.com';

    /**
     * Fetch episodes and cast for a TV series by its TVDB ID.
     * Episodes and cast are fetched concurrently when the cache is cold,
     * reducing worst-case latency from ~28s sequential to ~18s.
     *
     * @return array{
     *     episodes: array<int, array<int, array{seasonNumber: int, episodeNumber: int, title: string, airDate: ?string, overview: ?string}>>,
     *     cast: array<int, array{actor: string, character: string, photo: ?string}>
     * }
     */
    public function fetchSeriesData(int $tvdbId): array
    {
        $tvMazeId = $this->lookupTvMazeId($tvdbId);

        if (! $tvMazeId) {
            return ['episodes' => [], 'cast' => []];
        }

        $episodesKey = "tvmaze_episodes_{$tvMazeId}";
        $castKey = "tvmaze_cast_{$tvMazeId}";

        $episodes = Cache::get($episodesKey);
        $cast = Cache::get($castKey);

        if ($episodes !== null && $cast !== null) {
            return ['episodes' => $episodes, 'cast' => $cast];
        }

        // Fetch whichever are missing in parallel rather than sequentially.
        try {
            [$episodesResponse, $castResponse] = Http::pool(fn ($pool) => [
                $pool->timeout(10)->get(self::BASE_URL."/shows/{$tvMazeId}/episodes", ['specials' => 1]),
                $pool->timeout(10)->get(self::BASE_URL."/shows/{$tvMazeId}/cast"),
            ]);
        } catch (\Exception) {
            return ['episodes' => $episodes ?? [], 'cast' => $cast ?? []];
        }

        if ($episodes === null) {
            $episodes = $this->parseEpisodes($episodesResponse);
            Cache::put($episodesKey, $episodes, now()->addHours(24));
        }

        if ($cast === null) {
            $cast = $this->parseCast($castResponse);
            Cache::put($castKey, $cast, now()->addHours(24));
        }

        return ['episodes' => $episodes, 'cast' => $cast];
    }

    /**
     * @return array<int, array<int, array{seasonNumber: int, episodeNumber: int, title: string, airDate: ?string, overview: ?string}>>
     */
    public function fetchEpisodesByTvdbId(int $tvdbId): array
    {
        $tvMazeId = $this->lookupTvMazeId($tvdbId);

        if (! $tvMazeId) {
            return [];
        }

        return Cache::remember("tvmaze_episodes_{$tvMazeId}", now()->addHours(24), function () use ($tvMazeId) {
            $response = Http::baseUrl(self::BASE_URL)
                ->timeout(10)
                ->get("/shows/{$tvMazeId}/episodes", ['specials' => 1]);

            return $this->parseEpisodes($response);
        });
    }

    private function lookupTvMazeId(int $tvdbId): ?int
    {
        return Cache::remember("tvmaze_lookup_{$tvdbId}", now()->addHours(12), function () use ($tvdbId) {
            $response = Http::baseUrl(self::BASE_URL)
                ->timeout(8)
                ->get('/lookup/shows', ['thetvdb' => $tvdbId]);

            if (! $response->successful()) {
                return null;
            }

            $id = $response->json('id');

            return $id ? (int) $id : null;
        });
    }

    /**
     * @return array<int, array<int, array{seasonNumber: int, episodeNumber: int, title: string, airDate: ?string, overview: ?string}>>
     */
    private function parseEpisodes(mixed $response): array
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return [];
        }

        return collect($response->json() ?? [])
            ->map(fn ($ep) => [
                'seasonNumber' => (int) ($ep['season'] ?? 0),
                'episodeNumber' => (int) ($ep['number'] ?? 0),
                'title' => $ep['name'] ?? 'Unknown',
                'airDate' => $ep['airdate'] ?? null,
                'overview' => isset($ep['summary']) ? strip_tags((string) $ep['summary']) : null,
            ])
            ->groupBy('seasonNumber')
            ->map(fn ($eps) => $eps->values()->all())
            ->all();
    }

    /**
     * @return array<int, array{actor: string, character: string, photo: ?string}>
     */
    private function parseCast(mixed $response): array
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return [];
        }

        return collect($response->json() ?? [])
            ->filter(fn ($m) => ! ($m['voice'] ?? false) && ! ($m['self'] ?? false))
            ->map(fn ($m) => [
                'actor' => $m['person']['name'] ?? 'Unknown',
                'character' => $m['character']['name'] ?? '',
                'photo' => $m['person']['image']['medium'] ?? null,
            ])
            ->values()
            ->all();
    }
}
