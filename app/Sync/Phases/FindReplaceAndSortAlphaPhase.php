<?php

namespace App\Sync\Phases;

use App\Jobs\RunPlaylistFindReplaceRules;
use App\Jobs\RunPlaylistSortAlpha;
use App\Models\Playlist;
use App\Models\SyncRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 1 of the post-sync pipeline: apply Find/Replace rules and the
 * Sort Alpha sort order to channel names.
 *
 * Both steps live in this single phase because they must run in sequence
 * (sort_alpha keys off names that find_replace may have rewritten). The
 * underlying jobs are dispatched via Bus::chain when both apply.
 *
 * Honours the `playlist:{id}:find_replace_ran` cache marker that
 * {@see RunPlaylistFindReplaceRules} writes when F/R already ran earlier in
 * this sync window (e.g. before STRM sync). The marker is consumed via
 * Cache::pull so it cannot bleed into a subsequent sync.
 */
class FindReplaceAndSortAlphaPhase extends AbstractPhase
{
    public static function slug(): string
    {
        return 'find_replace_and_sort_alpha';
    }

    public function shouldRun(Playlist $playlist): bool
    {
        return $this->hasFindReplaceRules($playlist) || $this->hasSortAlphaRules($playlist);
    }

    protected function execute(SyncRun $run, Playlist $playlist, array $context): ?array
    {
        $hasFindReplace = $this->hasFindReplaceRules($playlist);
        $hasSortAlpha = $this->hasSortAlphaRules($playlist);

        // Find/Replace may already have been run earlier in the sync window
        // (e.g. by ProcessVodChannelsComplete so STRM uses processed names).
        // Consume the marker atomically — it must not bleed into the next sync.
        if ($hasFindReplace && Cache::pull(RunPlaylistFindReplaceRules::ranMarkerKey($playlist))) {
            $hasFindReplace = false;
        }

        $dispatched = [];

        if ($hasFindReplace && $hasSortAlpha) {
            Bus::chain([
                new RunPlaylistFindReplaceRules($playlist),
                new RunPlaylistSortAlpha($playlist),
            ])->dispatch();
            $dispatched = ['find_replace', 'sort_alpha'];
        } elseif ($hasFindReplace) {
            dispatch(new RunPlaylistFindReplaceRules($playlist));
            $dispatched = ['find_replace'];
        } elseif ($hasSortAlpha) {
            dispatch(new RunPlaylistSortAlpha($playlist));
            $dispatched = ['sort_alpha'];
        }

        return ['name_processing_dispatched' => $dispatched];
    }

    private function hasFindReplaceRules(Playlist $playlist): bool
    {
        return collect($playlist->find_replace_rules ?? [])
            ->contains(fn ($r) => $r['enabled'] ?? false);
    }

    private function hasSortAlphaRules(Playlist $playlist): bool
    {
        return collect($playlist->sort_alpha_config ?? [])
            ->contains(fn ($r) => $r['enabled'] ?? false);
    }
}
