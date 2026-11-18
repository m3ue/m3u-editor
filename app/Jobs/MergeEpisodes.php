<?php

namespace App\Jobs;

use App\Models\Episode;
use App\Models\EpisodeFailover;
use App\Models\Series;
use App\Models\SeriesFailover;
use App\Models\User;
use App\Services\SeriesFailoverLinker;
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
            ->whereIn('playlist_id', $this->mergePlaylistIds)
            // AIOStreams series manage their own episode failovers; they still pair
            // by episode TMDB ID in the ungrouped pass, as before series linking.
            ->whereNull('aio_integration_id');

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

        $linker = app(SeriesFailoverLinker::class);
        $masterEpisodes = $linker->episodesForSeries($master->id);
        $desiredEpisodePairs = [];
        $sortOrder = 1;

        foreach ($failoverSeries as $failover) {
            // Without events: the merge links (and cleans up) episodes itself below.
            SeriesFailover::withoutEvents(fn () => SeriesFailover::updateOrCreate(
                [
                    'series_id' => $master->id,
                    'series_failover_id' => $failover->id,
                ],
                [
                    'user_id' => $this->user->id,
                    'sort' => $sortOrder,
                ]
            ));
            $this->linkedSeriesCount++;

            foreach ($linker->pairEpisodes($masterEpisodes, $linker->episodesForSeries($failover->id)) as [$masterEpisode, $failoverEpisode]) {
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

    protected function linkEpisode(Episode $master, Episode $failover, int $sortOrder): void
    {
        app(SeriesFailoverLinker::class)->linkEpisode($master, $failover, $sortOrder, $this->user->id);

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

        $staleLinkIds = EpisodeFailover::whereIn('episode_id', $groupEpisodeIds)
            ->whereIn('episode_failover_id', $groupEpisodeIds)
            ->get(['id', 'episode_id', 'episode_failover_id'])
            ->reject(fn (EpisodeFailover $link): bool => isset($desiredEpisodePairs[$link->episode_id.':'.$link->episode_failover_id]))
            ->pluck('id')
            ->all();

        if ($staleLinkIds !== []) {
            EpisodeFailover::whereIn('id', $staleLinkIds)->delete();
        }
    }

    /**
     * Episodes whose series did not match another series can still pair by their own
     * TMDB ID (for example when one provider's series carries the wrong TMDB ID).
     * Episodes of matched series take part too, so an ungrouped series can attach to
     * a matched group, but a pair where both series are grouped is left to the
     * series-level pass.
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
            ->cursor();

        $this->eachGroup(
            $episodes,
            fn (Episode $episode): string => (string) (int) $episode->tmdb_id,
            function (Collection $group): void {
                if ($group->every(fn (Episode $episode): bool => $this->isGrouped($episode))) {
                    return;
                }

                $sorted = $this->sortByPlaylistPriority($group);
                $master = $sorted->first();

                // Append after any links the series-level pass gave this master, and keep
                // the order of links that already exist.
                $sortOrder = (int) EpisodeFailover::where('episode_id', $master->id)->max('sort');

                foreach ($sorted->where('id', '!=', $master->id) as $failover) {
                    if ($this->isGrouped($master) && $this->isGrouped($failover)) {
                        continue;
                    }

                    $exists = EpisodeFailover::where('episode_id', $master->id)
                        ->where('episode_failover_id', $failover->id)
                        ->exists();

                    if (! $exists) {
                        $this->linkEpisode($master, $failover, ++$sortOrder);
                    }
                }
            },
        );
    }

    protected function isGrouped(Episode $episode): bool
    {
        return isset($this->groupedSeriesIds[$episode->series_id]);
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
