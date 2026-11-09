<?php

namespace App\Models;

use App\Traits\HasPlaylistScopedDisplayLabels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause;

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
     * or a list of them, showing the imported category's custom name alongside the
     * provider's when it has been renamed. With $includePlaylistName the source
     * playlist is appended so same-named categories from different sources can be
     * told apart.
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
            ->leftJoin('categories', function (JoinClause $join): void {
                $join->on('categories.name_internal', '=', 'source_categories.name')
                    ->on('categories.playlist_id', '=', 'source_categories.playlist_id');
            })
            ->whereIn('source_categories.playlist_id', $playlistIds)
            ->whereIn('source_categories.id', $ids)
            ->selectRaw('source_categories.id as id, source_categories.name as provider_name, categories.name as custom_name')
            ->when($includePlaylistName, fn ($query) => self::selectPlaylistName($query, 'source_categories'))
            ->get();

        return self::labelsById($rows);
    }
}
