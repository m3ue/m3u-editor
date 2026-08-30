<?php

namespace App\Console\Commands;

use App\Jobs\SyncDynamicGroups;
use App\Models\Playlist;
use Illuminate\Console\Command;

/**
 * Refresh every playlist's TMDB-derived DynamicGroup rows independent of the
 * normal playlist-sync pipeline. Run on a daily cron so list endpoints
 * (Trending, Popular, …) track TMDB's changes even when no sync was
 * scheduled for the source playlist.
 *
 * Skips playlists whose config has no enabled rules (the job itself would
 * early-return, but bailing here avoids the dispatch hop).
 */
class RefreshDynamicGroups extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:refresh-dynamic-groups';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh TMDB dynamic groups (Trending / Popular / …) for every playlist that has them enabled';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = 0;
        $skipped = 0;

        // cursor() over the playlist set — never ->all()/->get() on a table
        // that grows with users. The dynamic_groups_config JSONB column is
        // only filtered via whereNotNull here; the rule-list filter is
        // intentionally done in PHP because the column is JSON-encoded and
        // a JSON predicate would have to scan all rows anyway.
        Playlist::query()
            ->whereNotNull('dynamic_groups_config')
            ->cursor()
            ->each(function (Playlist $playlist) use (&$count, &$skipped): void {
                $rules = collect($playlist->dynamic_groups_config ?? []);
                $hasEnabled = $rules->contains(fn (array $rule): bool => (bool) ($rule['enabled'] ?? false));

                if (! $hasEnabled) {
                    $skipped++;

                    return;
                }

                dispatch(new SyncDynamicGroups(playlistId: $playlist->id));
                $count++;
            });

        $this->info("Dispatched SyncDynamicGroups for {$count} playlist(s); skipped {$skipped} with no enabled rules.");

        return self::SUCCESS;
    }
}
