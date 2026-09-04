<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceCategory extends Model
{
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
        $ids = array_values(array_filter($ids, fn ($value): bool => is_numeric($value)));
        $playlistIds = array_values(array_filter((array) $playlistIds, fn ($value): bool => is_numeric($value)));
        if (empty($playlistIds) || empty($ids)) {
            return [];
        }

        return static::query()
            ->when($includePlaylistName, fn ($query) => $query->leftJoin('playlists', 'playlists.id', '=', 'source_categories.playlist_id'))
            ->whereIn('source_categories.playlist_id', $playlistIds)
            ->whereIn('source_categories.id', $ids)
            ->selectRaw('source_categories.id as id, source_categories.name as label')
            ->when($includePlaylistName, fn ($query) => $query->selectRaw('playlists.name as playlist_name'))
            ->get()
            ->mapWithKeys(fn ($row): array => [
                $row->id => $includePlaylistName && filled($row->playlist_name)
                    ? "{$row->label} ({$row->playlist_name})"
                    : $row->label,
            ])
            ->toArray();
    }
}
