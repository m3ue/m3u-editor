<?php

namespace App\Console\Commands;

use App\Enums\CacheDispatchResult;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Services\CachedContentDispatchService;
use Illuminate\Console\Command;

/**
 * Manually queue cache downloads for every enabled VOD channel and episode
 * in a playlist (or every playlist). Not scheduled: on a large catalog this
 * can queue thousands of multi-GB downloads, so it's an explicit operator
 * action. Use `--dry-run` first to see how many items would be queued.
 */
class CacheContentCommand extends Command
{
    protected $signature = 'cache:content
                                {--playlist= : Limit to a single playlist ID}
                                {--dry-run : Report what would be queued without queuing anything}';

    protected $description = 'Queue cache downloads for every enabled VOD channel and episode';

    public function handle(CachedContentDispatchService $dispatchService): int
    {
        if (! $dispatchService->isEnabled()) {
            $this->warn('Caching is disabled in Settings; nothing queued.');

            return self::SUCCESS;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $playlistId = $this->option('playlist');
        $totals = ['queued' => 0, 'skipped' => 0];

        $playlists = Playlist::query()
            ->when($playlistId !== null, fn ($q) => $q->where('id', (int) $playlistId));

        foreach ($playlists->cursor() as $playlist) {
            $counts = ['queued' => 0, 'skipped' => 0];

            $channels = Channel::query()
                ->where('playlist_id', $playlist->id)
                ->where('is_vod', true)
                ->where('enabled', true);
            $episodes = Episode::query()
                ->where('playlist_id', $playlist->id)
                ->where('enabled', true)
                ->with('series:id,name,tmdb_id,tvdb_id');

            foreach ([$channels, $episodes] as $query) {
                foreach ($query->lazyById(500) as $item) {
                    $item->setRelation('playlist', $playlist);

                    $wouldQueue = $isDryRun
                        ? $dispatchService->canCache($item) && ! $item->cachedContentFile()->exists()
                        : $dispatchService->dispatch($item) === CacheDispatchResult::Queued;

                    $counts[$wouldQueue ? 'queued' : 'skipped']++;
                }
            }

            $totals['queued'] += $counts['queued'];
            $totals['skipped'] += $counts['skipped'];

            $this->line(sprintf(
                '  Playlist %d (%s): %s=%d, skipped=%d',
                $playlist->id,
                $playlist->name ?? '(no name)',
                $isDryRun ? 'would-queue' : 'queued',
                $counts['queued'],
                $counts['skipped'],
            ));
        }

        $this->info(sprintf(
            '%s %s=%d, skipped=%d',
            $isDryRun ? '[DRY RUN]' : 'Done.',
            $isDryRun ? 'would-queue' : 'queued',
            $totals['queued'],
            $totals['skipped'],
        ));

        return self::SUCCESS;
    }
}
