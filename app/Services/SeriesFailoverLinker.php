<?php

namespace App\Services;

use App\Jobs\ProcessM3uImportSeriesEpisodes;
use App\Models\Episode;
use App\Models\EpisodeFailover;
use App\Models\Series;
use App\Models\SeriesFailover;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Derives episode failovers from series failovers (SeriesFailover). Shared by the
 * series merge, the Series edit page, and the play-time repair, so every path pairs
 * episodes the same way.
 */
class SeriesFailoverLinker
{
    /**
     * How long a play-time repair waits before checking the same episode again.
     */
    public const REPAIR_COOLDOWN_SECONDS = 600;

    /**
     * @return Collection<int, Episode>
     */
    public function episodesForSeries(int $seriesId): Collection
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
    public function pairEpisodes(Collection $masterEpisodes, Collection $failoverEpisodes): array
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
     * Link the episodes of a series to the episodes of each of its failover series,
     * in failover order. Only adds or re-sorts links; never removes them.
     *
     * @return int Number of episode links created or updated.
     */
    public function linkEpisodesForSeries(Series $series): int
    {
        return $this->linkEpisodes($series, $series->failoverSeries()->get(['series.id'])->values());
    }

    /**
     * Pair a series against the given failover series (in order). The whole series is
     * paired so duplicate keys are judged the same way as a full merge, but with
     * $onlyEpisodeId only that episode's links are written.
     *
     * @param  Collection<int, Series>  $failoverSeries
     */
    protected function linkEpisodes(Series $series, Collection $failoverSeries, ?int $onlyEpisodeId = null): int
    {
        if ($failoverSeries->isEmpty()) {
            return 0;
        }

        $masterEpisodes = $this->episodesForSeries($series->id);
        $linked = 0;

        foreach ($failoverSeries as $index => $failover) {
            foreach ($this->pairEpisodes($masterEpisodes, $this->episodesForSeries($failover->id)) as [$masterEpisode, $failoverEpisode]) {
                if ($onlyEpisodeId !== null && $masterEpisode->id !== $onlyEpisodeId) {
                    continue;
                }

                $this->linkEpisode($masterEpisode, $failoverEpisode, $index + 1, $series->user_id);
                $linked++;
            }
        }

        return $linked;
    }

    /**
     * Remove the episode links between a series and series it is no longer linked to.
     *
     * @param  array<int>  $unlinkedSeriesIds
     */
    public function unlinkEpisodes(Series $series, array $unlinkedSeriesIds): void
    {
        if ($unlinkedSeriesIds === []) {
            return;
        }

        EpisodeFailover::query()
            ->whereIn('episode_id', Episode::where('series_id', $series->id)->select('id'))
            ->whereIn('episode_failover_id', Episode::whereIn('series_id', $unlinkedSeriesIds)->select('id'))
            ->delete();
    }

    /**
     * Play-time repair: when an episode with no failovers belongs to a series that has
     * failover series, pair its series now (for example an episode added since the last
     * merge). Linked backups with no episodes yet get a metadata fetch queued, so the
     * next request can fail over. Checked at most once per cooldown per episode.
     */
    public function repairEpisode(Episode $episode): void
    {
        // Most episodes belong to series without failover series: one query, then out.
        if (! $episode->series_id || ! SeriesFailover::where('series_id', $episode->series_id)->exists()) {
            return;
        }

        if ($episode->failovers()->exists()) {
            return;
        }

        if (! Cache::add('series-failover-repair:'.$episode->id, true, self::REPAIR_COOLDOWN_SECONDS)) {
            return;
        }

        $series = Series::find($episode->series_id);

        if (! $series) {
            return;
        }

        $failoverSeries = $series->failoverSeries()->withExists('episodes')->get()->values();

        // Backups with no episodes yet get one fetch per cooldown, however many of the
        // series' episodes are played.
        $failoverSeries
            ->reject(fn (Series $backup): bool => (bool) $backup->episodes_exists)
            ->filter(fn (Series $backup): bool => Cache::add('series-failover-fetch:'.$backup->id, true, self::REPAIR_COOLDOWN_SECONDS))
            ->each(fn (Series $backup) => dispatch(new ProcessM3uImportSeriesEpisodes(
                playlistSeries: $backup,
                notify: false,
                sync_stream_files: false,
            )));

        $this->linkEpisodes($series, $failoverSeries, onlyEpisodeId: $episode->id);
    }

    public function linkEpisode(Episode $master, Episode $failover, int $sortOrder, int $userId): void
    {
        EpisodeFailover::updateOrCreate(
            [
                'episode_id' => $master->id,
                'episode_failover_id' => $failover->id,
            ],
            [
                'user_id' => $userId,
                'sort' => $sortOrder,
            ]
        );
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
}
