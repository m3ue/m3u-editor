<?php

use App\Models\PlaylistAlias;
use App\Services\EpgCacheService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data fix: switching an alias to a different playlist left the previous
     * playlist's bouquets attached (the form only detached within the new
     * target's bouquets), so they kept contributing their group names. A bouquet
     * only applies to aliases of its own target, so remove every pivot row where
     * the bouquet's target no longer matches the alias's. Ids are plucked first
     * because MySQL rejects a DELETE that subqueries its own table.
     */
    public function up(): void
    {
        $mismatched = DB::table('bouquet_playlist_alias')
            ->join('bouquets', 'bouquets.id', '=', 'bouquet_playlist_alias.bouquet_id')
            ->join('playlist_aliases', 'playlist_aliases.id', '=', 'bouquet_playlist_alias.playlist_alias_id')
            ->whereNot(function (Builder $query): void {
                foreach (['playlist_id', 'custom_playlist_id', 'merged_playlist_id'] as $column) {
                    // Both sides non-null so each branch is strictly true/false (never NULL).
                    $query->orWhere(fn (Builder $branch) => $branch
                        ->whereNotNull("bouquets.{$column}")
                        ->whereNotNull("playlist_aliases.{$column}")
                        ->whereColumn("bouquets.{$column}", "playlist_aliases.{$column}"));
                }
            })
            ->get(['bouquet_playlist_alias.id', 'bouquet_playlist_alias.playlist_alias_id']);

        if ($mismatched->isEmpty()) {
            return;
        }

        foreach ($mismatched->pluck('id')->chunk(1000) as $ids) {
            DB::table('bouquet_playlist_alias')->whereIn('id', $ids->all())->delete();
        }

        // The affected aliases' cached EPG was generated with the extra groups.
        PlaylistAlias::whereIn('id', $mismatched->pluck('playlist_alias_id')->unique()->all())
            ->each(fn (PlaylistAlias $alias) => EpgCacheService::clearPlaylistEpgCacheFile($alias));
    }

    public function down(): void
    {
        //
    }
};
