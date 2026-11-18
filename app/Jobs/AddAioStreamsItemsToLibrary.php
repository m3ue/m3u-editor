<?php

namespace App\Jobs;

use App\Models\MediaServerIntegration;
use App\Services\AioStreamsLibraryService;
use App\Services\AIOStreamsService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bulk "Add to Library" from the AIOStreams browse page: fetches each selected
 * title's meta (one AIOStreams request each, throttled by the integration's
 * rate limit) and adds it through AioStreamsLibraryService, the same path the
 * single-title buttons use. Series get only their Series row unless
 * $includeLatestSeason is set, which also adds the most recent season.
 */
class AddAioStreamsItemsToLibrary implements ShouldQueue
{
    use Queueable;

    /**
     * Upper bound on one bulk add, so a runaway selection can't queue
     * thousands of meta lookups (and stream resolves) in one go.
     */
    public const MAX_ITEMS = 100;

    /**
     * Lower bound when "Include latest season" is on: each series then adds a
     * whole season of stream lookups (about 10 episodes), all sharing the
     * integration's per-minute rate limit with this job's own meta lookups.
     */
    public const MAX_SERIES_WITH_LATEST_SEASON = 10;

    public int $tries = 1;

    /**
     * Runs on the default queue rather than 'aiostreams-resolve': at the
     * integration's per-minute rate limit, a full selection of meta lookups can
     * outlast that supervisor's 5 minute timeout. The stream resolves it
     * queues still go to 'aiostreams-resolve' as usual.
     */
    public int $timeout = 1800;

    /**
     * @param  array<int, array{type: string, id: string}>  $items
     */
    public function __construct(
        public int $integrationId,
        public array $items,
        public ?int $profileId = null,
        public bool $includeLatestSeason = false,
    ) {}

    public function handle(AioStreamsLibraryService $library): void
    {
        $integration = MediaServerIntegration::query()
            ->where('id', $this->integrationId)
            ->where('type', 'aiostreams')
            ->first();

        if (! $integration) {
            return;
        }

        $service = AIOStreamsService::make($integration);
        $profileId = $library->ownedProfileId($integration, $this->profileId);

        $added = 0;
        $skipped = 0;
        $failed = 0;
        $channelIds = [];
        $episodeIds = [];
        $seriesWithSeasons = 0;

        foreach (array_slice($this->items, 0, self::MAX_ITEMS) as $item) {
            $type = $item['type'] ?? null;
            $itemId = $item['id'] ?? null;

            if (! in_array($type, ['movie', 'series'], true) || ! is_string($itemId) || $itemId === '') {
                continue;
            }

            try {
                $meta = $service->fetchMeta($type, $itemId)['meta'] ?? null;
            } catch (Throwable $e) {
                Log::warning('AIOStreams bulk add: meta lookup failed', ['item' => $itemId, 'error' => $e->getMessage()]);
                $meta = null;
            }

            if (! is_array($meta)) {
                $failed++;

                continue;
            }

            if ($type === 'movie') {
                $channel = $library->addMovie($integration, $itemId, $meta, $profileId);

                if ($channel) {
                    $added++;
                    $channelIds[] = $channel->id;
                } else {
                    $skipped++;
                }

                continue;
            }

            $series = $library->findOrCreateSeries($integration, $itemId, $meta, $profileId);
            $newEpisodes = $this->includeLatestSeason && $seriesWithSeasons++ < self::MAX_SERIES_WITH_LATEST_SEASON
                ? $library->addLatestSeason($series, (array) ($meta['videos'] ?? []))
                : [];

            if ($series->wasRecentlyCreated || $newEpisodes !== []) {
                $added++;
                $library->queueTmdbEnrichment($integration, series: $series);
            } else {
                $skipped++;
            }

            foreach ($newEpisodes as $episode) {
                if ($episode->enabled) {
                    $episodeIds[] = $episode->id;
                }
            }
        }

        if ($channelIds !== [] || $episodeIds !== []) {
            NotifyAioStreamsResolutionComplete::dispatch($channelIds, $episodeIds, $integration->user_id, __('bulk add'))
                ->delay(now()->addSeconds(15));
        }

        $this->notify($integration, $added, $skipped, $failed);
    }

    /**
     * With tries = 1 a timeout or crash would otherwise end the bulk add
     * silently, part way through the selection.
     */
    public function failed(?Throwable $exception): void
    {
        $user = MediaServerIntegration::query()->find($this->integrationId)?->user;

        if (! $user) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(__('AIOStreams bulk add stopped early'))
            ->body(__('Some titles may not have been added. Titles already added are kept, so you can select the rest again.'))
            ->broadcast($user)
            ->sendToDatabase($user);
    }

    private function notify(MediaServerIntegration $integration, int $added, int $skipped, int $failed): void
    {
        $user = $integration->user;

        if (! $user) {
            return;
        }

        $lines = [trans_choice(':count title added|:count titles added', $added, ['count' => $added])];

        if ($skipped > 0) {
            $lines[] = __(':count already in library', ['count' => $skipped]);
        }

        if ($failed > 0) {
            $lines[] = __(':count could not be loaded from AIOStreams', ['count' => $failed]);
        }

        $notification = Notification::make()
            ->title(__('AIOStreams bulk add finished'))
            ->body(implode(' | ', $lines));

        ($added > 0 ? $notification->success() : $notification->warning())
            ->broadcast($user)
            ->sendToDatabase($user);
    }
}
