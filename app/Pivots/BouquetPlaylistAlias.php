<?php

namespace App\Pivots;

use App\Models\Bouquet;
use App\Models\PlaylistAlias;
use App\Services\EpgCacheService;
use Illuminate\Database\Eloquent\Relations\Pivot;
use InvalidArgumentException;

class BouquetPlaylistAlias extends Pivot
{
    protected $table = 'bouquet_playlist_alias';

    public $incrementing = true;

    public $timestamps = false;

    protected static function booted(): void
    {
        // Server-side attach invariant: a bouquet only ever applies to aliases of
        // its own playlist - its stored names are meaningless anywhere else. The
        // Filament form pre-filters options; this guard covers API/console writes.
        // Custom pivot classes make attach()/detach()/sync() fire these events.
        static::creating(function (self $pivot): void {
            $bouquet = Bouquet::find($pivot->bouquet_id);
            $alias = PlaylistAlias::find($pivot->playlist_alias_id);

            if (! $bouquet || ! $alias || ! $bouquet->appliesTo($alias)) {
                throw new InvalidArgumentException('A bouquet can only be attached to an alias of the same playlist.');
            }
        });

        // Attaching or detaching changes the alias's effective filter immediately,
        // so its cached EPG XML is stale.
        static::created(function (self $pivot): void {
            $alias = PlaylistAlias::find($pivot->playlist_alias_id);
            if ($alias) {
                EpgCacheService::clearPlaylistEpgCacheFile($alias);
            }
        });
        static::deleted(function (self $pivot): void {
            $alias = PlaylistAlias::find($pivot->playlist_alias_id);
            if ($alias) {
                EpgCacheService::clearPlaylistEpgCacheFile($alias);
            }
        });
    }
}
