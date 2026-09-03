<?php

namespace App\Models;

use App\Pivots\BouquetPlaylistAlias;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Bouquet extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'group_selections' => 'array',
        'auto_include_new_live' => 'boolean',
        'auto_include_new_vod' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function customPlaylist(): BelongsTo
    {
        return $this->belongsTo(CustomPlaylist::class);
    }

    public function playlistAliases(): BelongsToMany
    {
        return $this->belongsToMany(PlaylistAlias::class, 'bouquet_playlist_alias')
            ->using(BouquetPlaylistAlias::class);
    }

    /**
     * @return array<string>
     */
    public function getSelectedLiveGroupNames(): array
    {
        return $this->group_selections['selected_groups'] ?? [];
    }

    /**
     * @return array<string>
     */
    public function getSelectedVodGroupNames(): array
    {
        return $this->group_selections['selected_vod_groups'] ?? [];
    }

    /**
     * @return array<string>
     */
    public function getSelectedCategoryNames(): array
    {
        return $this->group_selections['selected_categories'] ?? [];
    }
}
