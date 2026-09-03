<?php

namespace App\Models;

use App\Enums\Status;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EpgMap extends Model
{
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'processing' => 'boolean',
        'candidates_building' => 'boolean',
        // 'override' => 'boolean',
        'progress' => 'float',
        'user_id' => 'integer',
        'epg_id' => 'integer',
        'channel_count' => 'integer',
        'mapped_count' => 'integer',
        'settings' => 'array',
        'channels' => 'array',
        'group_ids' => 'array',
        'status' => Status::class,
        'candidates_built_at' => 'datetime',
        'candidates_progress' => 'float',
    ];

    /**
     * Build the auto-generated display name for a map from its EPG and
     * (optional) playlist names. Single source of truth for the naming
     * convention used at creation time (see MapPlaylistChannelsToEpg and
     * ProcessM3uImportComplete) and by the AppServiceProvider model hooks
     * that keep the name in sync when an EPG or playlist is renamed.
     */
    public static function buildName(string $epgName, ?string $playlistName): string
    {
        return $playlistName !== null
            ? "{$epgName} -> {$playlistName} mapping"
            : "{$epgName} custom channel mapping";
    }

    /**
     * Re-derive this map's name from its current EPG/playlist relations.
     *
     * Manually customised names are left untouched: the stored name is only
     * rewritten when it still matches the value that would have been
     * generated from the supplied previous EPG/playlist names (which default
     * to the current names when a specific rename isn't being tracked).
     */
    public function syncGeneratedName(?string $previousEpgName = null, ?string $previousPlaylistName = null): void
    {
        $epgName = $this->epg?->name;
        if ($epgName === null) {
            return;
        }

        $playlistName = $this->playlist?->name;
        $previousName = self::buildName($previousEpgName ?? $epgName, $previousPlaylistName ?? $playlistName);

        if (filled($this->name) && $this->name !== $previousName) {
            return;
        }

        $newName = self::buildName($epgName, $playlistName);
        if ($this->name !== $newName) {
            $this->update(['name' => $newName]);
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function epg(): BelongsTo
    {
        return $this->belongsTo(Epg::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(EpgMapCandidate::class);
    }

    /**
     * Channels already mapped to an EPG entry within this map's scope
     * (playlist, optionally narrowed to specific channels/groups). Mirrors
     * the scoping used to compute `current_mapped_count` in
     * MapPlaylistChannelsToEpg so the two stay consistent.
     */
    public function mappedChannels(): HasMany
    {
        return $this->hasMany(Channel::class, 'playlist_id', 'playlist_id')
            ->where('channels.user_id', $this->user_id)
            ->when($this->playlist_id === null, fn ($query) => $query->whereRaw('1 = 0'))
            ->eligibleForEpgMapping()
            ->whereNotNull('epg_channel_id')
            ->when($this->channels, fn ($query) => $query->whereIn('id', $this->channels))
            ->when(! $this->channels && $this->group_ids, fn ($query) => $query->whereIn('group_id', $this->group_ids));
    }
}
