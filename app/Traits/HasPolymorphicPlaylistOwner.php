<?php

namespace App\Traits;

use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Shared polymorphic-ish ownership for models (DvrSetting, PlaylistRequestSetting)
 * that belong to exactly one of Playlist, CustomPlaylist, or MergedPlaylist via
 * separate nullable FK columns (playlist_id / custom_playlist_id / merged_playlist_id).
 *
 * A real morph column isn't used because these settings need real FK constraints
 * and simple joins/eager-loads against each concrete playlist type.
 */
trait HasPolymorphicPlaylistOwner
{
    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function customPlaylist(): BelongsTo
    {
        return $this->belongsTo(CustomPlaylist::class);
    }

    public function mergedPlaylist(): BelongsTo
    {
        return $this->belongsTo(MergedPlaylist::class);
    }

    public function owner(): Playlist|CustomPlaylist|MergedPlaylist|null
    {
        return $this->playlist ?? $this->customPlaylist ?? $this->mergedPlaylist;
    }

    /**
     * The channels() relation of whichever model owns this record. Use this
     * (or ownerChannelsSubquery()) rather than materializing ids — playlists
     * can have hundreds of thousands of channels.
     */
    public function ownerChannels(): ?Relation
    {
        return $this->owner()?->channels();
    }

    /**
     * A single-column subquery over the owner's channels() relation, for use
     * inside whereIn('channels.id', ...) / whereIn('channels.group_id', ...)
     * without ever loading channel rows/ids into PHP. Resolves at the database
     * as `WHERE channels.<col> IN (SELECT <select> FROM ...)`, so it stays cheap
     * regardless of how many channels the owner has.
     */
    public function ownerChannelsSubquery(string $select = 'channels.id'): ?Builder
    {
        $owner = $this->owner();

        return $owner?->channels()->getQuery()->select($select);
    }

    /**
     * The real Playlist ids reachable through this owner. For a Playlist owner
     * that's just itself; for a MergedPlaylist it's the (small, bounded) set of
     * merged source playlists — cheap to pluck directly, no channels touched.
     * CustomPlaylist has no direct source-playlists relation cheap enough to
     * pluck here, so it returns empty.
     *
     * @return array<int, int>
     */
    public function ownerPlaylistIds(): array
    {
        $owner = $this->owner();

        return match (true) {
            $owner instanceof Playlist => [$owner->id],
            $owner instanceof MergedPlaylist => $owner->playlists()->pluck('playlists.id')->all(),
            default => [],
        };
    }

    /**
     * The FK column name that should hold the given owner's id.
     */
    public static function ownerColumn(Playlist|CustomPlaylist|MergedPlaylist $owner): string
    {
        return match (true) {
            $owner instanceof Playlist => 'playlist_id',
            $owner instanceof CustomPlaylist => 'custom_playlist_id',
            $owner instanceof MergedPlaylist => 'merged_playlist_id',
        };
    }

    /**
     * Attributes that set this record's owner to the given model, nulling the others.
     *
     * @return array<string, int|null>
     */
    public static function ownerAttributes(Playlist|CustomPlaylist|MergedPlaylist $owner): array
    {
        return [
            'playlist_id' => $owner instanceof Playlist ? $owner->id : null,
            'custom_playlist_id' => $owner instanceof CustomPlaylist ? $owner->id : null,
            'merged_playlist_id' => $owner instanceof MergedPlaylist ? $owner->id : null,
        ];
    }
}
