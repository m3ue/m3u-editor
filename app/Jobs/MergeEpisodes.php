<?php

namespace App\Jobs;

use App\Models\Episode;
use App\Models\EpisodeFailover;
use App\Models\Series;
use App\Models\SeriesFailover;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Links the same series across playlists, then links their episodes as failovers.
 *
 * Series are matched by TMDB ID, or by TVDB ID when the TMDB ID is missing. Within a
 * matched group, episodes are paired by episode TMDB ID when both sides have one,
 * otherwise by season and episode number. Episodes of series that did not match at
 * the series level can still pair by episode TMDB ID.
 */
class MergeEpisodes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Playlist IDs taking part in this merge, in priority order.
     *
     * @var array<int>
     */
    protected array $mergePlaylistIds = [];

    /**
     * @var array<int, int> playlist id => priority (lower wins)
     */
    protected array $playlistPriority = [];

    /**
     * Series placed in a series-level group during this run.
     *
     * @var array<int, true>
     */
    protected array $groupedSeriesIds = [];

    protected int $linkedSeriesCount = 0;

    protected int $processedEpisodeCount = 0;

    protected int $deactivatedEpisodeCount = 0;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public User $user,
        public Collection $playlists,
        public int $playlistId,
        public bool $deactivateFailoverEpisodes = false,
        public bool $forceCompleteRemerge = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $playlistIds = $this->playlists->map(function ($item) {
            return is_array($item) ? $item['playlist_failover_id'] : $item;
        })->values();

        if ($this->playlistId) {
            $playlistIds->prepend($this->playlistId);
        }

        $this->mergePlaylistIds = $playlistIds->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $this->playlistPriority = array_flip($this->mergePlaylistIds);

        if ($this->mergePlaylistIds !== []) {
            $this->linkSeriesGroups(
                'tmdb_id',
                fn (Builder $query) => $query->whereNotNull('tmdb_id'),
            );
            $this->linkSeriesGroups(
                'tvdb_id',
                fn (Builder $query) => $query->whereNull('tmdb_id')->whereNotNull('tvdb_id')->where('tvdb_id', '!=', ''),
            );
            $this->linkUngroupedEpisodesByTmdbId();
        }

        $body = __(':series series linked, :episodes episode failover relationship(s) created or updated.', [
            'series' => $this->linkedSeriesCount,
            'episodes' => $this->processedEpisodeCount,
        ]);

        if ($this->deactivatedEpisodeCount > 0) {
            $body .= ' '.__(':count failover episode(s) deactivated.', ['count' => $this->deactivatedEpisodeCount]);
        }

        Notification::make()
            ->success()
            ->title(__('Series merge completed'))
            ->body($body)
            ->broadcast($this->user)
            ->sendToDatabase($this->user);
    }

    /**
     * Stream the merge's series ordered by an identity column and link each group
     * that shares a value. Only one group is held in memory at a time.
     *
     * @param  callable(Builder): mixed  $scope
     */
    protected function linkSeriesGroups(string $column, callable $scope): void
    {
        $query = Series::query()
            ->select(['id', 'playlist_id', 'tmdb_id', 'tvdb_id'])
            ->where('user_id', $this->user->id)
            ->whereIn('playlist_id', $this->mergePlaylistIds);

        $scope($query);

        $this->eachGroup(
            $query->orderBy($column)->orderBy('id')->cursor(),
            fn (Series $series): string => (string) $series->getRawOriginal($column),
            fn (Collection $group) => $this->linkSeriesGroup($group),
        );
    }

    /**
     * @param  Collection<int, Series>  $group
     */
    protected function linkSeriesGroup(Collection $group): void
    {
        $groupSeriesIds = $group->pluck('id')->all();

        foreach ($groupSeriesIds as $seriesId) {
            $this->groupedSeriesIds[$seriesId] = true;
        }

        $master = $this->selectMasterSeries($group, $groupSeriesIds);
        $failoverSeries = $this->sortByPlaylistPriority($group->where('id', '!=', $master->id));

        // The group is fully owned by this merge (every member is in a merge playlist),
        // so it is reconciled to exactly master -> each other member.
        SeriesFailover::whereIn('series_id', $groupSeriesIds)
            ->whereIn('series_failover_id', $groupSeriesIds)
            ->where(fn ($query) => $query
                ->where('series_id', '!=', $master->id)
                ->orWhere('series_failover_id', $master->id))
            ->delete();

        $masterEpisodes = $this->episodesForSeries($master->id);
        $desiredEpisodePairs = [];
        $sortOrder = 1;

        foreach ($failoverSeries as $failover) {
            SeriesFailover::updateOrCreate(
                [
                    'series_id' => $master->id,
                    'series_failover_id' => $failover->id,
                ],
                [
                    'user_id' => $this->user->id,
                    'sort' => $sortOrder,
                ]
            );
            $this->linkedSeriesCount++;

            foreach ($this->pairEpisodes($masterEpisodes, $this->episodesForSeries($failover->id)) as [$masterEpisode, $failoverEpisode]) {
                $desiredEpisodePairs[$masterEpisode->id.':'.$failoverEpisode->id] = true;
                $this->linkEpisode($masterEpisode, $failoverEpisode, $sortOrder);
            }

            $sortOrder++;
        }

        $this->removeStaleEpisodeLinks($groupSeriesIds, $desiredEpisodePairs);
    }

    /**
     * Pick the master series: by playlist priority, keeping a series that is already
     * a failover inside this group out of the running unless re-merging completely.
     *
     * @param  Collection<int, Series>  $group
     * @param  array<int>  $groupSeriesIds
     */
    protected function selectMasterSeries(Collection $group, array $groupSeriesIds): Series
    {
        $candidates = $group;

        if (! $this->forceCompleteRemerge) {
            $existingFailoverIds = SeriesFailover::whereIn('series_id', $groupSeriesIds)
                ->whereIn('series_failover_id', $groupSeriesIds)
                ->pluck('series_failover_id')
                ->all();

            $candidates = $group->whereNotIn('id', $existingFailoverIds);

            if ($candidates->isEmpty()) {
                $candidates = $group;
            }
        }

        return $this->sortByPlaylistPriority($candidates)->first();
    }

    /**
     * @return Collection<int, Episode>
     */
    protected function episodesForSeries(int $seriesId): Collection
    {
        return Episode::query()
            ->select(['id', 'playlist_id', 'series_id', 'tmdb_id', 'season', 'episode_num', 'enabled'])
            ->where('series_id', $seriesId)
            ->get();
    }

    /**
     * Pair a failover series' episodes with the master's: by episode TMDB ID when
     * both have one (survives numbering differences between providers), otherwise
     * by season and episode number. Ambiguous keys (used twice in one series) and
     * already-paired episodes are skipped.
     *
     * @param  Collection<int, Episode>  $masterEpisodes
     * @param  Collection<int, Episode>  $failoverEpisodes
     * @return array<int, array{0: Episode, 1: Episode}>
     */
    protected function pairEpisodes(Collection $masterEpisodes, Collection $failoverEpisodes): array
    {
        $masterByTmdb = $this->uniqueIndex($masterEpisodes, fn (Episode $episode): ?string => $this->episodeTmdbKey($episode));
        $masterByNumber = $this->uniqueIndex($masterEpisodes, fn (Episode $episode): ?string => $this->episodeNumberKey($episode));
        $failoverTmdbCounts = $failoverEpisodes->map(fn (Episode $episode): ?string => $this->episodeTmdbKey($episode))->filter()->countBy();
        $failoverNumberCounts = $failoverEpisodes->map(fn (Episode $episode): ?string => $this->episodeNumberKey($episode))->filter()->countBy();

        $pairs = [];
        $pairedMasterIds = [];

        foreach ($failoverEpisodes as $failoverEpisode) {
            $master = null;
            $tmdbKey = $this->episodeTmdbKey($failoverEpisode);
            $numberKey = $this->episodeNumberKey($failoverEpisode);

            if ($tmdbKey !== null && ($failoverTmdbCounts[$tmdbKey] ?? 0) === 1) {
                $master = $masterByTmdb[$tmdbKey] ?? null;
            }

            if ($master === null && $numberKey !== null && ($failoverNumberCounts[$numberKey] ?? 0) === 1) {
                $candidate = $masterByNumber[$numberKey] ?? null;
                $candidateTmdbKey = $candidate !== null ? $this->episodeTmdbKey($candidate) : null;

                // Don't pair by number when both sides carry conflicting TMDB IDs.
                $conflictingTmdb = $tmdbKey !== null
                    && $candidateTmdbKey !== null
                    && $candidateTmdbKey !== $tmdbKey;

                $master = $conflictingTmdb ? null : $candidate;
            }

            if ($master === null || isset($pairedMasterIds[$master->id])) {
                continue;
            }

            $pairedMasterIds[$master->id] = true;
            $pairs[] = [$master, $failoverEpisode];
        }

        return $pairs;
    }

    /**
     * Index episodes by a key, leaving out keys that more than one episode shares.
     *
     * @param  Collection<int, Episode>  $episodes
     * @param  callable(Episode): ?string  $keyFor
     * @return array<string, Episode>
     */
    protected function uniqueIndex(Collection $episodes, callable $keyFor): array
    {
        $index = [];
        $duplicates = [];

        foreach ($episodes as $episode) {
            $key = $keyFor($episode);

            if ($key === null) {
                continue;
            }

            if (isset($index[$key])) {
                $duplicates[$key] = true;
            }

            $index[$key] = $episode;
        }

        return array_diff_key($index, $duplicates);
    }

    protected function episodeTmdbKey(Episode $episode): ?string
    {
        return empty($episode->tmdb_id) ? null : (string) (int) $episode->tmdb_id;
    }

    protected function episodeNumberKey(Episode $episode): ?string
    {
        if (empty($episode->season) || empty($episode->episode_num)) {
            return null;
        }

        return (int) $episode->season.':'.(int) $episode->episode_num;
    }

    protected function linkEpisode(Episode $master, Episode $failover, int $sortOrder): void
    {
        EpisodeFailover::updateOrCreate(
            [
                'episode_id' => $master->id,
                'episode_failover_id' => $failover->id,
            ],
            [
                'user_id' => $this->user->id,
                'sort' => $sortOrder,
            ]
        );

        if ($this->deactivateFailoverEpisodes && $failover->enabled) {
            $failover->update(['enabled' => false]);
            $this->deactivatedEpisodeCount++;
        }

        $this->processedEpisodeCount++;
    }

    /**
     * Remove episode links between this group's episodes that the current pairing no
     * longer produces (master changed, episode renumbered). AIOStreams failover clones
     * are hidden from Episode queries, so their links are never touched.
     *
     * @param  array<int>  $groupSeriesIds
     * @param  array<string, true>  $desiredEpisodePairs
     */
    protected function removeStaleEpisodeLinks(array $groupSeriesIds, array $desiredEpisodePairs): void
    {
        $groupEpisodeIds = Episode::whereIn('series_id', $groupSeriesIds)->pluck('id')->all();

        if ($groupEpisodeIds === []) {
            return;
        }

        EpisodeFailover::whereIn('episode_id', $groupEpisodeIds)
            ->whereIn('episode_failover_id', $groupEpisodeIds)
            ->get(['id', 'episode_id', 'episode_failover_id'])
            ->reject(fn (EpisodeFailover $link): bool => isset($desiredEpisodePairs[$link->episode_id.':'.$link->episode_failover_id]))
            ->each(fn (EpisodeFailover $link) => $link->delete());
    }

    /**
     * Episodes whose series did not match another series can still pair by their own
     * TMDB ID (for example when one provider's series carries the wrong TMDB ID).
     */
    protected function linkUngroupedEpisodesByTmdbId(): void
    {
        $existingFailoverEpisodeIds = $this->forceCompleteRemerge || $this->deactivateFailoverEpisodes
            ? []
            : EpisodeFailover::where('user_id', $this->user->id)
                ->whereHas('episode', fn (Builder $query) => $query->whereIn('playlist_id', $this->mergePlaylistIds))
                ->whereHas('episodeFailover', fn (Builder $query) => $query->whereIn('playlist_id', $this->mergePlaylistIds))
                ->pluck('episode_failover_id')
                ->all();

        $episodes = Episode::query()
            ->select(['id', 'playlist_id', 'series_id', 'tmdb_id', 'enabled'])
            ->where('user_id', $this->user->id)
            ->whereIn('playlist_id', $this->mergePlaylistIds)
            ->whereNotNull('tmdb_id')
            ->when($existingFailoverEpisodeIds !== [], fn ($query) => $query->whereNotIn('id', $existingFailoverEpisodeIds))
            ->orderBy('tmdb_id')
            ->orderBy('id')
            ->cursor()
            ->reject(fn (Episode $episode): bool => isset($this->groupedSeriesIds[$episode->series_id]));

        $this->eachGroup(
            $episodes,
            fn (Episode $episode): string => (string) (int) $episode->tmdb_id,
            function (Collection $group): void {
                $sorted = $this->sortByPlaylistPriority($group);
                $master = $sorted->first();

                $sortOrder = 1;
                foreach ($sorted->where('id', '!=', $master->id) as $failover) {
                    $this->linkEpisode($master, $failover, $sortOrder++);
                }
            },
        );
    }

    /**
     * Walk rows already ordered by a key and hand each run of two or more rows that
     * share the key to the callback, holding only the current run in memory.
     *
     * @param  iterable<int, Series|Episode>  $rows
     * @param  callable(Series|Episode): string  $keyFor
     * @param  callable(Collection): void  $callback
     */
    protected function eachGroup(iterable $rows, callable $keyFor, callable $callback): void
    {
        $group = [];
        $currentKey = null;

        foreach ($rows as $row) {
            $key = $keyFor($row);

            if ($currentKey !== null && $key !== $currentKey) {
                if (count($group) > 1) {
                    $callback(collect($group));
                }

                $group = [];
            }

            $currentKey = $key;
            $group[] = $row;
        }

        if (count($group) > 1) {
            $callback(collect($group));
        }
    }

    /**
     * @template TModel of Series|Episode
     *
     * @param  Collection<int, TModel>  $items
     * @return Collection<int, TModel>
     */
    protected function sortByPlaylistPriority(Collection $items): Collection
    {
        return $items->sortBy([
            fn (Series|Episode $a, Series|Episode $b): int => ($this->playlistPriority[$a->playlist_id] ?? PHP_INT_MAX) <=> ($this->playlistPriority[$b->playlist_id] ?? PHP_INT_MAX),
            fn (Series|Episode $a, Series|Episode $b): int => $a->id <=> $b->id,
        ])->values();
    }
}
