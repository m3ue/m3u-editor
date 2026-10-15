<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\PlaylistViewer;
use App\Models\ViewerWatchProgress;
use Illuminate\Support\Facades\Cache;

/**
 * Backs the Playlist Viewer "Add Progress" action: search TMDB for a movie
 * or series episode and record watch progress for it against a viewer.
 *
 * The TMDB pick is resolved to the matching library content (VOD channel or
 * episode) the same way stale rows are relinked (WatchProgressLinker), so a
 * resolved row is indistinguishable from one a client recorded. Titles that
 * aren't in the library are stored unlinked - stream_id NULL, tmdb_id set,
 * with denormalised TMDB metadata for display - and relink once the content
 * shows up.
 */
class ManualWatchProgressService
{
    public function __construct(
        private TmdbService $tmdb,
        private WatchProgressLinker $linker,
    ) {}

    /**
     * Episodes of one season as Select options, keyed by episode number.
     *
     * @return array<int, string>
     */
    public function episodeOptions(int $seriesTmdbId, int $seasonNumber): array
    {
        return collect($this->seasonDetails($seriesTmdbId, $seasonNumber)['episodes'] ?? [])
            ->filter(fn (array $episode): bool => ! empty($episode['episode_number']))
            ->mapWithKeys(fn (array $episode): array => [
                $episode['episode_number'] => "E{$episode['episode_number']}".(! empty($episode['name']) ? " - {$episode['name']}" : ''),
            ])
            ->all();
    }

    /**
     * Best-known runtime for the pick: the library item's probed/provider
     * duration first, falling back to TMDB's runtime.
     */
    public function suggestDurationSeconds(PlaylistViewer $viewer, string $mediaType, int $tmdbId, ?int $seasonNumber = null, ?int $episodeNumber = null): ?int
    {
        $resolved = $this->resolve($viewer, $mediaType, $tmdbId, $seasonNumber, $episodeNumber);
        if (! $resolved) {
            return null;
        }

        $libraryDuration = (int) (($resolved['target']?->info ?? [])['duration_secs'] ?? 0);
        if ($libraryDuration > 0) {
            return $libraryDuration;
        }

        return $resolved['runtime_minutes'] ? $resolved['runtime_minutes'] * 60 : null;
    }

    /**
     * Create or update the viewer's progress row for the pick. Returns null
     * when TMDB has no such title/episode.
     */
    public function record(
        PlaylistViewer $viewer,
        string $mediaType,
        int $tmdbId,
        ?int $seasonNumber,
        ?int $episodeNumber,
        int $positionSeconds,
        ?int $durationSeconds,
        bool $completed,
    ): ?ViewerWatchProgress {
        $resolved = $this->resolve($viewer, $mediaType, $tmdbId, $seasonNumber, $episodeNumber);
        if (! $resolved) {
            return null;
        }

        $target = $resolved['target'];
        $contentType = $mediaType === 'tv' ? 'episode' : 'vod';

        $progress = ViewerWatchProgress::firstOrNew([
            'playlist_viewer_id' => $viewer->id,
            'content_type' => $contentType,
            ...($target
                ? ['stream_id' => $target->id]
                : ['stream_id' => null, 'tmdb_id' => $resolved['tmdb_id']]),
        ]);

        $progress->forceFill([
            'tmdb_id' => $resolved['tmdb_id'],
            'position_seconds' => $positionSeconds,
            'duration_seconds' => $durationSeconds ?? $this->suggestDurationSeconds($viewer, $mediaType, $tmdbId, $seasonNumber, $episodeNumber),
            'completed' => $completed,
            'last_watched_at' => now(),
            ...($contentType === 'episode' ? [
                'series_id' => $target?->series_id,
                'season_number' => $target?->season ?? $seasonNumber,
                'episode_number' => $target?->episode_num ?? $episodeNumber,
            ] : []),
            // Linked rows read their display metadata from the library.
            ...($target ? [] : $resolved['metadata']),
        ])->save();

        // An earlier unlinked entry for the same title is superseded now that
        // it resolved to library content.
        if ($target) {
            ViewerWatchProgress::where('playlist_viewer_id', $viewer->id)
                ->where('content_type', $contentType)
                ->whereNull('stream_id')
                ->where('tmdb_id', $resolved['tmdb_id'])
                ->delete();
        }

        return $progress;
    }

    /**
     * @return array{tmdb_id: int, target: Channel|Episode|null, runtime_minutes: ?int, metadata: array<string, mixed>}|null
     */
    private function resolve(PlaylistViewer $viewer, string $mediaType, int $tmdbId, ?int $seasonNumber, ?int $episodeNumber): ?array
    {
        $playlist = $viewer->viewerable;

        if ($mediaType !== 'tv') {
            $details = $this->movieDetails($tmdbId);
            if (! $details) {
                return null;
            }

            return [
                'tmdb_id' => $tmdbId,
                'target' => $playlist ? $this->linker->findContentByTmdbId('vod', $tmdbId, $playlist) : null,
                'runtime_minutes' => $details['runtime'] ?? null,
                'metadata' => $this->metadata($details, $details['title'] ?? null, $details['release_date'] ?? null),
            ];
        }

        if ($seasonNumber === null || $episodeNumber === null) {
            return null;
        }

        $series = $this->seriesDetails($tmdbId);
        $episode = collect($this->seasonDetails($tmdbId, $seasonNumber)['episodes'] ?? [])
            ->firstWhere('episode_number', $episodeNumber);
        if (! $series || ! $episode || empty($episode['tmdb_id'])) {
            return null;
        }

        $target = null;
        if ($playlist) {
            // Progress rows key episodes by the episode's own TMDB id; fall back
            // to series TMDB id + S/E for libraries whose episodes were never
            // individually enriched.
            $target = $this->linker->findContentByTmdbId('episode', (int) $episode['tmdb_id'], $playlist)
                ?? Episode::where('user_id', $playlist->user_id)
                    ->where('season', $seasonNumber)
                    ->where('episode_num', $episodeNumber)
                    ->whereHas('series', fn ($query) => $query->where('tmdb_id', $tmdbId))
                    ->first();
        }

        return [
            'tmdb_id' => (int) $episode['tmdb_id'],
            'target' => $target,
            'runtime_minutes' => $episode['runtime'] ?? null,
            'metadata' => [
                ...$this->metadata($series, $series['name'] ?? null, $series['first_air_date'] ?? null),
                'episode_title' => $episode['name'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function metadata(array $details, ?string $title, ?string $date): array
    {
        return [
            'title' => $title,
            'thumbnail_url' => $details['poster_url'] ?? null,
            'backdrop_url' => $details['backdrop_url'] ?? null,
            'plot' => $details['overview'] ?? null,
            'year' => $date ? substr($date, 0, 4) : null,
            'rating' => isset($details['vote_average']) ? (string) round((float) $details['vote_average'], 1) : null,
        ];
    }

    // The details calls append credits/videos/images and aren't cached by
    // TmdbService; the Add form hits them on every reactive update.

    private function movieDetails(int $tmdbId): ?array
    {
        return Cache::remember("watch-progress-tmdb:movie:{$tmdbId}", now()->addHour(), fn () => $this->tmdb->getMovieDetails($tmdbId));
    }

    private function seriesDetails(int $tmdbId): ?array
    {
        return Cache::remember("watch-progress-tmdb:tv:{$tmdbId}", now()->addHour(), fn () => $this->tmdb->getTvSeriesDetails($tmdbId));
    }

    private function seasonDetails(int $tmdbId, int $seasonNumber): ?array
    {
        return Cache::remember("watch-progress-tmdb:tv:{$tmdbId}:season:{$seasonNumber}", now()->addHour(), fn () => $this->tmdb->getSeasonDetails($tmdbId, $seasonNumber));
    }
}
