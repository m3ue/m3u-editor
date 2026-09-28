<?php

namespace App\Console\Commands;

use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Services\WatchProgressLinker;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\Relation;

class PruneOrphanedWatchProgress extends Command
{
    protected $signature = 'progress:prune-orphaned
                            {--dry-run : Preview what would be backfilled/relinked/deleted without making changes}
                            {--playlist-type= : Scope to one playlist - playlist|custom_playlist|merged_playlist|alias (requires --playlist-id; default: all playlists)}
                            {--playlist-id= : The id of the playlist to scope to (requires --playlist-type)}';

    protected $description = 'Backfill tmdb_id on vod/episode watch-progress rows that still resolve (so a future resync can relink them), relink rows whose stream_id already went stale, and delete the ones with no tmdb_id to relink from - e.g. after a media-server library flush regenerated content ids';

    public function handle(WatchProgressLinker $linker): int
    {
        $scope = $this->resolvePlaylistScope();
        if ($scope === false) {
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->dryRun($linker, $scope);

            return self::SUCCESS;
        }

        $stats = $linker->pruneOrphaned($scope);

        $this->info("Checked {$stats['checked']} vod/episode progress rows: backfilled {$stats['backfilled']}, relinked {$stats['relinked']}, deleted {$stats['deleted']} unrecoverable.");

        return self::SUCCESS;
    }

    private function dryRun(WatchProgressLinker $linker, Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias|null $scope): void
    {
        $preview = $linker->preview($scope);

        foreach ($preview['items'] as $item) {
            $verb = match ($item['action']) {
                'backfill' => 'would backfill tmdb_id',
                'relink' => 'would relink',
                'delete' => 'would delete (unrecoverable)',
            };
            $this->line("  - {$verb}: {$item['content_type']} progress #{$item['id']} (stream_id={$item['stream_id']}, tmdb_id=".($item['tmdb_id'] ?? 'null').')');
        }

        $this->info("[DRY RUN] Checked {$preview['checked']} vod/episode progress rows.");
    }

    /**
     * Resolve --playlist-type/--playlist-id into a model, or null for "all
     * playlists". Returns false (and prints an error) on invalid input.
     */
    private function resolvePlaylistScope(): Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias|null|false
    {
        $type = $this->option('playlist-type');
        $id = $this->option('playlist-id');

        if (! $type && ! $id) {
            return null;
        }

        if (! $type || ! $id) {
            $this->error('--playlist-type and --playlist-id must be used together.');

            return false;
        }

        $modelClass = Relation::getMorphedModel($type);
        if (! $modelClass) {
            $this->error("Unknown --playlist-type \"{$type}\". Expected one of: playlist, custom_playlist, merged_playlist, alias.");

            return false;
        }

        $playlist = $modelClass::find($id);
        if (! $playlist) {
            $this->error("No {$type} found with id {$id}.");

            return false;
        }

        return $playlist;
    }
}
