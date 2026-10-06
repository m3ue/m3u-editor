<?php

namespace App\Services;

use App\Enums\CachedContentFileStatus;
use App\Enums\CachedContentManagedBy;
use App\Enums\CacheDispatchResult;
use App\Jobs\DownloadCachedContentFile;
use App\Models\ArrCacheMovie;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Illuminate\Database\UniqueConstraintViolationException;

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
 *  - New items can go to Radarr/Sonarr instead (CachedContentArrService).
 */
class CachedContentDispatchService
{
    public function __construct(private CachedContentArrService $arr) {}

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
     * `$automatic` is the dynamic-group auto-cache: new rows are marked
     * group-managed, and Failed rows are left alone (the download job
     * already retried for a day). A manual call on a group-managed row
     * makes it manual, so group retention never deletes it.
     *
     * `$viaArr` false keeps the provider even when a caching Radarr/Sonarr
     * is set up.
     */
    public function dispatch(Channel|Episode $item, bool $automatic = false, bool $viaArr = true): CacheDispatchResult
    {
        if (! $this->isEnabled()) {
            return CacheDispatchResult::Disabled;
        }

        if (! $this->canCache($item)) {
            return CacheDispatchResult::Unavailable;
        }

        $existing = $item->cachedContentFile()->first();

        if ($existing) {
            if (! $automatic) {
                $existing->keep();
            }

            if (in_array($existing->status, [CachedContentFileStatus::Pending, CachedContentFileStatus::Downloading], true)) {
                return CacheDispatchResult::AlreadyQueued;
            }

            if ($existing->isPlayable()) {
                return CacheDispatchResult::AlreadyCached;
            }

            if ($automatic && $existing->status === CachedContentFileStatus::Failed) {
                return CacheDispatchResult::Unavailable;
            }

            // Failed, or Completed with the file missing on disk.
            return $this->requeue($existing) ? CacheDispatchResult::Queued : CacheDispatchResult::Unavailable;
        }

        $shared = CachedContentFile::findServableFor($item);
        if ($shared?->isPlayable()) {
            return CacheDispatchResult::AlreadyCached;
        }

        $sent = $viaArr ? $this->arr->request($item, $automatic) : null;
        if ($sent !== null) {
            return $sent;
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
     * Queue every episode of a series, or add the whole series to Sonarr
     * when it handles caching. Returns how many items ended up in each
     * result bucket.
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

        // reorder(): the relation sorts by episode_num, which Postgres
        // rejects alongside DISTINCT.
        $seasons = $series->episodes()->reorder()->whereNotNull('season')->distinct()->pluck('season')
            ->map(fn ($season): int => (int) $season)
            ->all();

        if ($this->arr->requestSeries($series, $seasons)) {
            $counts[CacheDispatchResult::SentToArr->value] = $series->episodes()->count();

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
     * Queue a dynamic group's auto-cache: each VOD member, or the latest
     * season of each series member, top-ranked first. Members already on
     * the user's media server are skipped (local media wins), and a copy
     * the group cached before the match appeared is marked dropped so
     * retention releases it. A Never expire rule also keeps its movies out
     * of Radarr cleanup, like Cache Now.
     *
     * @return array<string, int> keyed by CacheDispatchResult value
     */
    public function dispatchForDynamicGroup(DynamicGroup $group): array
    {
        $counts = $this->emptyCounts();
        $settings = $group->cacheSettings();

        if (! $this->isEnabled() || ! ($settings['enabled'] ?? false)) {
            return $counts;
        }

        $playlist = $group->playlist;

        foreach ($group->cacheMembers($settings['max_items'])->cursor() as $member) {
            // cursor() can't eager load; every member shares the group's
            // playlist, so hand it over directly.
            $member->setRelation('playlist', $playlist);

            if ($member instanceof Channel) {
                $this->dispatchGroupItem($member, $group, $settings['never_expire'], $counts);

                if ($settings['never_expire']) {
                    ArrCacheMovie::keep($playlist->user_id, (int) $member->getTmdbId());
                }

                continue;
            }

            foreach ($member->episodes()->inLatestSeason()->cursor() as $episode) {
                $episode->setRelation('series', $member);
                $episode->setRelation('playlist', $playlist);

                $this->dispatchGroupItem($episode, $group, $settings['never_expire'], $counts);
            }
        }

        return $counts;
    }

    /**
     * Dispatch one auto-cache item and link the group to the file it
     * manages (clearing `dropped_at` when the item is back in the group).
     * A Never expire rule keeps the file instead, like Cache Now. Manual
     * files and copies shared from another playlist are never linked.
     *
     * @param  array<string, int>  $counts
     */
    private function dispatchGroupItem(Channel|Episode $item, DynamicGroup $group, bool $neverExpire, array &$counts): void
    {
        if (app(MediaSourcePreferenceService::class)->hasEligibleMatch($item)) {
            $item->cachedContentFile()->first()?->dynamicGroups()
                ->wherePivotNull('dropped_at')
                ->updateExistingPivot($group->id, ['dropped_at' => now()]);

            return;
        }

        $counts[$this->dispatch($item, automatic: true)->value]++;

        $file = $item->cachedContentFile()->first();
        if ($file?->managed_by !== CachedContentManagedBy::DynamicGroup) {
            return;
        }

        if ($neverExpire) {
            $file->keep();

            return;
        }

        $file->dynamicGroups()->syncWithoutDetaching([$group->id => ['dropped_at' => null]]);
    }

    /**
     * @return array<string, int> keyed by CacheDispatchResult value, all zero
     */
    private function emptyCounts(): array
    {
        return array_fill_keys(array_map(fn (CacheDispatchResult $r): string => $r->value, CacheDispatchResult::cases()), 0);
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
            CacheDispatchResult::SentToArr => Notification::make()
                ->success()
                ->title($isEpisode ? __('Sent to Sonarr') : __('Sent to Radarr'))
                ->body(__('Track progress on the Download Queue page. If it can\'t be found, run Cache Now again to download it from the provider instead.')),
            CacheDispatchResult::InArrLibrary => Notification::make()
                ->info()
                ->title($isEpisode ? __('Already in Sonarr') : __('Already in Radarr'))
                ->body(__('It\'s already downloaded there and plays from your media server once the server has synced it.')),
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

        $sent = $counts[CacheDispatchResult::SentToArr->value] ?? 0;
        $queued = ($counts[CacheDispatchResult::Queued->value] ?? 0) + $sent;
        $skipped = ($counts[CacheDispatchResult::AlreadyCached->value] ?? 0)
            + ($counts[CacheDispatchResult::AlreadyQueued->value] ?? 0)
            + ($counts[CacheDispatchResult::InArrLibrary->value] ?? 0);
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
            ->body($sent > 0
                ? __(':sent sent to :arr, :skipped already cached or queued, :unavailable without a cacheable source.', [
                    'sent' => $sent,
                    'arr' => $episodes ? 'Sonarr' : 'Radarr',
                    'skipped' => $skipped,
                    'unavailable' => $unavailable,
                ])
                : __(':skipped already cached or queued, :unavailable without a cacheable source.', [
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
