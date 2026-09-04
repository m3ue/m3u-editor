<?php

namespace App\Models;

use App\Traits\HasPlaylistScopedDisplayLabels;
use Illuminate\Database\Eloquent\Model;

class SourceCategory extends Model
{
    use HasPlaylistScopedDisplayLabels;

    protected $table = 'source_categories';

    public function playlist()
    {
        return $this->belongsTo(Playlist::class);
    }

    /**
     * Resolve display labels for the given source category IDs across one playlist
     * or a list of them. With $includePlaylistName the source playlist is appended
     * so same-named categories from different sources can be told apart.
     *
     * @param  int|array<int>|null  $playlistIds
     * @param  array<int|string>  $ids
     * @return array<int, string> source category id => display name
     */
    public static function displayLabelsForIds(int|array|null $playlistIds, array $ids, bool $includePlaylistName = false): array
    {
        $ids = self::numericIds($ids);
        $playlistIds = self::numericIds($playlistIds);
        if (empty($playlistIds) || empty($ids)) {
            return [];
        }

        $rows = static::query()
            ->whereIn('source_categories.playlist_id', $playlistIds)
            ->whereIn('source_categories.id', $ids)
            ->selectRaw('source_categories.id as id, source_categories.name as label')
            ->when($includePlaylistName, fn ($query) => self::selectPlaylistName($query, 'source_categories'))
            ->get();

        return self::labelsById($rows);
    }
}
