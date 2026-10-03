<?php

namespace App\Services;

use App\Enums\CachedContentFileStatus;
use App\Enums\CachedContentManagedBy;
use App\Enums\CacheDispatchResult;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Queues cache downloads for VOD channels and series episodes.
 *
 * Every entry point (Cache Now actions, the series "Cache all episodes"
 * action, `cache:content`, and the downloads widget's Retry) goes through
 * `dispatch()` / `requeue()` so the rules live in one place:
 *  - Nothing happens while `enable_cache` is off.
 *  - Only plain Playlist VOD channels and episodes with a non-HLS source
 *    URL can be cached.
 *  - An item has at most one cached file (unique on cacheable). A
 *    Completed file whose bytes are missing on disk, or a Failed one, is
 *    re-queued rather than reported as done.
 *  - A playable copy shared by another of the same user's playlists
 *    (`share_cache_across_playlists`) counts as already cached.
 *
 * Dynamic-group auto-caching enters through `dispatchForDynamicGroup()`,
 * which calls `dispatch(automatic: true)` per member: rows it creates are
 * stamped `managed_by = CachedContentManagedBy::DynamicGroup` so group
 * retention may release them later, members with an eligible media-server
 * match are skipped (local media always wins), and recently failed rows
 * are not re-queued (cooldown below).
 */
class CachedContentDispatchService
{
    /**
     * Hours after a failure before the automatic (dynamic-group) path may
     * re-queue an item. Manual Cache Now / Retry re-queue immediately.
     */
    public const AUTO_RETRY_COOLDOWN_HOURS = 24;

    /**
     * How often dispatchForDynamicGroup() re-queries the group's tracked
     * bytes while enforcing the rule's max-GB budget.
     */
    private const BUDGET_RECHECK_EVERY = 25;

    /**
     * Whether the global `enable_cache` toggle is on.
     */
    public function isEnabled(): bool
    {
        return (bool) (app(GeneralSettings::class)->enable_cache ?? false);
    }

    /**
     * Whether `$item` can be cached at all (ignores the global toggle).
     */
    public function canCache(Channel|Episode $item): bool
    {
        if ($item instanceof Channel && ! $item->is_vod) {
            return false;
        }

        if (! $item->playlist_id || ! $item->playlist instanceof Playlist) {
            return false;
        }

        $url = $item->cacheSourceUrl();
        if ($url === '') {
            return false;
        }

        // HLS manifests can't be cached as a single file.
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return ! str_ends_with($path, '.m3u8');
    }

    /**
     * Queue a download for one channel or episode.
     *
     * `$automatic` marks the dynamic-group path: rows it creates are
     * `managed_by = CachedContentManagedBy::DynamicGroup`, and a Failed row
     * inside the auto-retry cooldown returns CoolingDown instead of
     * re-queueing. Manual callers (the default) adopt an existing
     * group-managed row, turning it manual so group retention never
     * deletes it afterwards.
     */
    public function dispatch(Channel|Episode $item, bool $automatic = false): CacheDispatchResult
    {
        if (! $this->isEnabled()) {
            return CacheDispatchResult::Disabled;
        }

        if (! $this->canCache($item)) {
            return CacheDispatchResult::Unavailable;
        }

        $existing = $item->cachedContentFile()->first();

        if ($existing) {
            if (! $automatic && $existing->managed_by === CachedContentManagedBy::DynamicGroup) {
                // Manual Cache Now on a group-created file adopts it.
                $existing->forceFill(['managed_by' => null])->save();
            }

            if (in_array($existing->status, [CachedContentFileStatus::Pending, CachedContentFileStatus::Downloading], true)) {
                return CacheDispatchResult::AlreadyQueued;
            }

            if ($existing->isPlayable()) {
                return CacheDispatchResult::AlreadyCached;
            }

            if (
                $automatic
                && $existing->status === CachedContentFileStatus::Failed
                && $existing->last_failed_at !== null
                && $existing->last_failed_at->isAfter(now()->subHours(self::AUTO_RETRY_COOLDOWN_HOURS))
            ) {
                return CacheDispatchResult::CoolingDown;
            }

            // Failed, or Completed with the file missing on disk.
            return $this->requeue($existing) ? CacheDispatchResult::Queued : CacheDispatchResult::Unavailable;
        }

        $shared = CachedContentFile::findServableFor($item);
        if ($shared?->isPlayable()) {
            return CacheDispatchResult::AlreadyCached;
        }

        /** @var Playlist $playlist */
        $playlist = $item->playlist;

        try {
            $file = CachedContentFile::create([
                'user_id' => $playlist->user_id,
                'playlist_id' => $playlist->id,
                'cacheable_type' => $item->getMorphClass(),
                'cacheable_id' => $item->getKey(),
                'content_type' => $item instanceof Channel ? 'movie' : 'episode',
                'tmdb_id' => $this->identityValue($item, 'tmdb_id'),
                'tvdb_id' => $this->identityValue($item, 'tvdb_id'),
                'season_number' => $item instanceof Episode ? $item->season : null,
                'episode_number' => $item instanceof Episode ? $item->episode_num : null,
                'content_fingerprint' => $item->cacheFingerprint(),
                'title' => $this->resolveTitle($item),
                'status' => CachedContentFileStatus::Pending,
                'managed_by' => $automatic ? CachedContentManagedBy::DynamicGroup : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent dispatch created the row first.
            return CacheDispatchResult::AlreadyQueued;
        }

        dispatch(new DownloadCachedContentFile($file->id));

        return CacheDispatchResult::Queued;
    }

    /**
     * Queue every episode of a series. Returns how many items ended up in
     * each result bucket.
     *
     * @return array<string, int> keyed by CacheDispatchResult value
     */
    public function dispatchSeries(Series $series): array
    {
        $counts = $this->emptyCounts();

        if (! $this->isEnabled()) {
            $counts[CacheDispatchResult::Disabled->value] = 1;

            return $counts;
        }

        $playlist = $series->playlist;

        foreach ($series->episodes()->orderBy('season')->orderBy('episode_num')->cursor() as $episode) {
            // cursor() can't eager load; the series and its playlist are the
            // same for every episode, so hand them over directly.
            $episode->setRelation('series', $series);
            if ($playlist && (int) $episode->playlist_id === (int) $playlist->id) {
                $episode->setRelation('playlist', $playlist);
            }

            $counts[$this->dispatch($episode)->value]++;
        }

        return $counts;
    }

    /**
     * Queue every cacheable channel or episode in `$items` and aggregate the
     * result counts (bulk Cache Now entry point).
     *
     * @param  iterable<Channel|Episode>  $items
     * @return array<string, int> keyed by CacheDispatchResult value
     */
    public function dispatchMany(iterable $items): array
    {
        $counts = $this->emptyCounts();

        foreach ($items as $item) {
            $counts[$this->dispatch($item)->value]++;
        }

        return $counts;
    }

    /**
     * Run `dispatchSeries()` for every series in `$series` and merge the
     * counts into one summary (bulk Cache all episodes entry point).
     *
     * @param  iterable<Series>  $series
     * @return array<string, int> keyed by CacheDispatchResult value
     */
    public function dispatchManySeries(iterable $series): array
    {
        $counts = $this->emptyCounts();

        foreach ($series as $item) {
            foreach ($this->dispatchSeries($item) as $bucket => $count) {
                $counts[$bucket] += $count;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, int> keyed by CacheDispatchResult value, all zero
     */
    private function emptyCounts(): array
    {
        return array_fill_keys(array_map(fn (CacheDispatchResult $r): string => $r->value, CacheDispatchResult::cases()), 0);
    }

    /**
     * Queue cache downloads for every member of a dynamic group whose rule
     * has caching enabled. VOD rules cache each member channel; series
     * rules cache only the latest season (highest episodes.season) of each
     * member series. Members are processed in dynamic_group_items.position
     * (TMDB rank) order so the caps keep the top-ranked items.
     *
     * @return array<string, int> keyed by CacheDispatchResult value
     */
    public function dispatchForDynamicGroup(DynamicGroup $group): array
    {
        $counts = $this->emptyCounts();

        if (! $this->isEnabled()) {
            $counts[CacheDispatchResult::Disabled->value] = 1;

            return $counts;
        }

        $settings = DynamicGroup::cacheSettings($group->ruleFromConfig());
        if ($settings === null) {
            return $counts;
        }

        $playlist = $group->playlist;
        $isSeries = $group->type === 'series';

        $members = $isSeries ? $group->series() : $group->channels();
        $members->orderByPivot('position');
        if ($settings['max_items'] !== null) {
            $members->limit((int) $settings['max_items']);
        }

        // Soft max-GB budget: stop queueing new items once this group's
        // tracked bytes (Completed file_size_bytes + in-flight
        // bytes_expected, 0 when unknown) reach the limit. Freshly queued
        // rows have no bytes_expected yet, so the cap is enforced across
        // runs, not within the first one — max_items is the hard limit.
        // Re-checked every BUDGET_RECHECK_EVERY items, not once per item.
        $trackedBytes = $settings['max_bytes'] === null ? 0 : null;
        $itemsSinceCheck = 0;

        $budgetReached = function () use ($group, $settings, &$trackedBytes, &$itemsSinceCheck): bool {
            if ($settings['max_bytes'] === null) {
                return false;
            }

            if ($trackedBytes === null || $itemsSinceCheck >= self::BUDGET_RECHECK_EVERY) {
                $trackedBytes = (int) $group->cachedContentFiles()
                    ->sum(DB::raw('COALESCE(cached_content_files.file_size_bytes, cached_content_files.bytes_expected, 0)'));
                $itemsSinceCheck = 0;
            }

            return $trackedBytes >= $settings['max_bytes'];
        };

        foreach ($members->cursor() as $member) {
            // cursor() can't eager load; every member shares the group's
            // playlist, so hand it over directly.
            $member->setRelation('playlist', $playlist);

            if (! $isSeries) {
                if ($budgetReached()) {
                    return $this->finishGroupDispatch($group, $counts);
                }

                $this->dispatchGroupItem($member, $group, $settings, $counts);
                $itemsSinceCheck++;

                continue;
            }

            // Series rules cache only the latest season of each series.
            $latestSeason = (int) $member->episodes()->max('season');

            foreach ($member->episodes()->where('season', $latestSeason)->orderBy('episode_num')->cursor() as $episode) {
                if ($budgetReached()) {
                    return $this->finishGroupDispatch($group, $counts);
                }

                $episode->setRelation('series', $member);
                $episode->setRelation('playlist', $playlist);

                $this->dispatchGroupItem($episode, $group, $settings, $counts);
                $itemsSinceCheck++;
            }
        }

        return $this->finishGroupDispatch($group, $counts);
    }

    /**
     * Dispatch one in-scope group member and attach (or refresh) its
     * provenance row carrying the rule's retention snapshot.
     *
     * Local media always wins: a member with an eligible media-server
     * match is skipped entirely — no download, no provenance row — and
     * keeps playing from the media server. The skipped member counts
     * toward neither the budget nor new downloads (top N is applied to
     * the member list before this).
     *
     * @param  array{retention: string, retention_days: int, max_items: int|null, max_bytes: int|null}  $settings
     * @param  array<string, int>  $counts
     */
    private function dispatchGroupItem(Channel|Episode $item, DynamicGroup $group, array $settings, array &$counts): void
    {
        if (app(MediaSourcePreferenceService::class)->hasEligibleMatch($item)) {
            $counts[CacheDispatchResult::MediaServerAvailable->value]++;

            return;
        }

        $counts[$this->dispatch($item, automatic: true)->value]++;

        $file = $item->cachedContentFile()->first();
        if (($file?->managed_by ?? null) !== CachedContentManagedBy::DynamicGroup) {
            // Manual files and shared sibling-playlist copies (no row on
            // this item) are not group-managed; never attach to them.
            return;
        }

        // syncWithoutDetaching refreshes the snapshot and clears dropped_at
        // when an item re-enters scope, and is how a renamed rule's new
        // group adopts the old group's files (their NULL-group pivot rows
        // are then released under their own snapshots). Adoption timing:
        // SyncDynamicGroups::runSync() materializes the renamed rule's new
        // group (queueing this fan-out job) and deletes the old group in
        // the same run, so adoption is queued at the moment the old rows
        // go NULL. It does NOT rely on cron ordering — the daily refresh
        // runs at 04:15, after the 03:00 retention sweep; NULL-group rows
        // carry a 24h floor grace (see
        // CachedContentRetentionService::deleteReleasedPivotRows) so a
        // same-day sweep can't race the queue.
        $file->dynamicGroups()->syncWithoutDetaching([
            $group->id => [
                'retention' => $settings['retention'],
                'retention_days' => $settings['retention_days'],
                'dropped_at' => null,
            ],
        ]);

        if ($settings['retention'] === 'never_expire') {
            // Pin the file: retention never deletes it. The pivot row stays
            // so the group budget still counts it.
            $file->forceFill(['managed_by' => null])->save();
        }
    }

    /**
     * Stamp dropped_at for files that just left the group's scope, then
     * hand the counts back.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function finishGroupDispatch(DynamicGroup $group, array $counts): array
    {
        app(CachedContentRetentionService::class)->markDroppedForGroup($group);

        return $counts;
    }

    /**
     * Reset a Failed (or missing-file Completed) row to Pending and queue it
     * again. Returns false when the row's source item no longer exists or
     * can't be cached.
     */
    public function requeue(CachedContentFile $file): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $item = $file->cacheable;
        if (! $item instanceof Channel && ! $item instanceof Episode) {
            return false;
        }

        if (! $this->canCache($item)) {
            return false;
        }

        $file->deleteStoredFile();

        $file->forceFill([
            'status' => CachedContentFileStatus::Pending,
            'file_path' => null,
            'file_size_bytes' => null,
            'failure_count' => 0,
            'last_failed_at' => null,
            'last_error_message' => null,
            'bytes_downloaded' => null,
            'bytes_expected' => null,
            'bytes_per_second' => null,
            'last_progress_at' => null,
        ])->save();

        dispatch(new DownloadCachedContentFile($file->id));

        return true;
    }

    /**
     * Filament notification for a single-item Cache Now result.
     */
    public static function cacheNowNotification(Channel|Episode $item, CacheDispatchResult $result): Notification
    {
        $isEpisode = $item instanceof Episode;

        return match ($result) {
            CacheDispatchResult::Queued => Notification::make()
                ->success()
                ->title(__('Cache download queued'))
                ->body(__('Track progress on the Cached Downloads page.')),
            CacheDispatchResult::AlreadyCached => Notification::make()
                ->info()
                ->title(__('Already cached'))
                ->body($isEpisode
                    ? __('This episode already has a completed cached file.')
                    : __('This VOD already has a completed cached file.')),
            CacheDispatchResult::AlreadyQueued => Notification::make()
                ->info()
                ->title(__('Already queued for caching'))
                ->body($isEpisode
                    ? __('A pending or downloading cached file already exists for this episode.')
                    : __('A pending or downloading cached file already exists for this VOD.')),
            CacheDispatchResult::Disabled => Notification::make()
                ->warning()
                ->title(__('Could not queue cache'))
                ->body(__('Caching is disabled in Settings.')),
            CacheDispatchResult::Unavailable => Notification::make()
                ->danger()
                ->title(__('Could not queue cache'))
                ->body($isEpisode
                    ? __('This episode has no cacheable source URL.')
                    : __('This VOD has no cacheable source URL.')),
            CacheDispatchResult::CoolingDown => Notification::make()
                ->info()
                ->title(__('Recently failed'))
                ->body(__('This item failed recently; automatic caching will retry it later.')),
            CacheDispatchResult::MediaServerAvailable => Notification::make()
                ->info()
                ->title(__('Available on your media server'))
                ->body(__('This item already exists on your media server, so it was not cached.')),
        };
    }

    /**
     * Filament notification summarizing a multi-item cache run: a series
     * "Cache all episodes" (`$episodes` true) or a bulk VOD Cache Now.
     *
     * @param  array<string, int>  $counts
     */
    public static function summaryNotification(array $counts, bool $episodes = true): Notification
    {
        if (($counts[CacheDispatchResult::Disabled->value] ?? 0) > 0) {
            return Notification::make()
                ->warning()
                ->title(__('Could not queue cache'))
                ->body(__('Caching is disabled in Settings.'));
        }

        $queued = $counts[CacheDispatchResult::Queued->value] ?? 0;
        $skipped = ($counts[CacheDispatchResult::AlreadyCached->value] ?? 0)
            + ($counts[CacheDispatchResult::AlreadyQueued->value] ?? 0)
            + ($counts[CacheDispatchResult::CoolingDown->value] ?? 0)
            + ($counts[CacheDispatchResult::MediaServerAvailable->value] ?? 0);
        $unavailable = $counts[CacheDispatchResult::Unavailable->value] ?? 0;

        $notification = Notification::make();
        $queued > 0 ? $notification->success() : $notification->info();

        $title = $episodes
            ? match (true) {
                $queued === 0 => __('No episodes queued'),
                $queued === 1 => __('Queued 1 episode for caching'),
                default => __('Queued :count episodes for caching', ['count' => $queued]),
            }
        : match (true) {
            $queued === 0 => __('No VODs queued'),
            $queued === 1 => __('Queued 1 VOD for caching'),
            default => __('Queued :count VODs for caching', ['count' => $queued]),
        };

        return $notification
            ->title($title)
            ->body(__(':skipped already cached or queued, :unavailable without a cacheable source.', [
                'skipped' => $skipped,
                'unavailable' => $unavailable,
            ]));
    }

    /**
     * TMDB/TVDB id stored on the row. Episodes carry their series' ids.
     */
    private function identityValue(Channel|Episode $item, string $column): ?string
    {
        $value = $item instanceof Episode
            ? ($item->series?->{$column} ?? ($column === 'tmdb_id' ? $item->tmdb_id : null))
            : $item->{$column};

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    /**
     * Display title persisted on the row so the downloads table never has to
     * look it up again.
     */
    private function resolveTitle(Channel|Episode $item): ?string
    {
        $title = trim((string) $item->display_title);

        if ($item instanceof Episode) {
            $seriesName = trim((string) $item->series?->name);
            $code = sprintf('S%02dE%02d', (int) $item->season, (int) $item->episode_num);
            $title = trim(implode(' - ', array_filter([$seriesName, $code, $title])));
        }

        return $title === '' ? null : mb_substr($title, 0, 500);
    }
}
