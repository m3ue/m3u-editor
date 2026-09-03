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

    /**
     * Rewrite provider group renames (old name => new name) into every
     * standard-target bouquet of the playlist. Called from the sync pipeline's
     * rename-detection pass, the same one that already rewrites import_prefs, so
     * bouquets keep matching within the same sync. Saves through Eloquent so the
     * EPG-cache invalidation hook fires for attached aliases.
     *
     * @param  array<string, string>  $renames
     */
    public static function applyProviderRenames(int $playlistId, string $type, array $renames): void
    {
        $key = $type === 'vod' ? 'selected_vod_groups' : 'selected_groups';

        self::where('playlist_id', $playlistId)->cursor()->each(function (self $bouquet) use ($key, $renames): void {
            $current = $bouquet->group_selections[$key] ?? [];
            if (empty($current)) {
                return;
            }

            $updated = array_values(array_unique(
                array_map(fn (string $name): string => $renames[$name] ?? $name, $current)
            ));

            if ($updated !== $current) {
                $bouquet->update([
                    'group_selections' => array_merge($bouquet->group_selections ?? [], [$key => $updated]),
                ]);
            }
        });
    }

    /**
     * Append newly-appeared provider group names to bouquets that opted in via
     * the per-type auto-include flag. Custom-target bouquets are structurally
     * excluded (playlist_id is NULL).
     *
     * @param  array<string>  $newNames
     */
    public static function appendNewGroupNames(int $playlistId, string $type, array $newNames): void
    {
        if (empty($newNames)) {
            return;
        }

        $flag = $type === 'vod' ? 'auto_include_new_vod' : 'auto_include_new_live';
        $key = $type === 'vod' ? 'selected_vod_groups' : 'selected_groups';

        self::where('playlist_id', $playlistId)->where($flag, true)->cursor()->each(function (self $bouquet) use ($key, $newNames): void {
            $current = $bouquet->group_selections[$key] ?? [];
            $updated = array_values(array_unique(array_merge($current, $newNames)));

            if ($updated !== $current) {
                $bouquet->update([
                    'group_selections' => array_merge($bouquet->group_selections ?? [], [$key => $updated]),
                ]);
            }
        });
    }
}
