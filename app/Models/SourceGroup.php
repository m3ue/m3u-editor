<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause;

class SourceGroup extends Model
{
    protected $table = 'source_groups';

    public function playlist()
    {
        return $this->belongsTo(Playlist::class);
    }

    /**
     * Resolve display labels for the given source group IDs.
     *
     * Uses the imported group's custom name (groups.name) when the group has been
     * imported, otherwise falls back to the source group's own name. Accepts one
     * playlist id or a list (merged-playlist aliases pick across every source);
     * with $includePlaylistName the source playlist is appended so same-named
     * groups from different sources can be told apart.
     *
     * @param  int|array<int>|null  $playlistIds
     * @param  array<int|string>  $ids
     * @return array<int, string> source group id => display name
     */
    public static function displayLabelsForIds(int|array|null $playlistIds, ?string $type, array $ids, bool $includePlaylistName = false): array
    {
        $ids = array_values(array_filter($ids, fn ($value): bool => is_numeric($value)));
        $playlistIds = array_values(array_filter((array) $playlistIds, fn ($value): bool => is_numeric($value)));
        if (empty($playlistIds) || empty($ids)) {
            return [];
        }

        return static::query()
            ->leftJoin('groups', function (JoinClause $join) use ($type): void {
                $join->on('groups.name_internal', '=', 'source_groups.name')
                    ->on('groups.playlist_id', '=', 'source_groups.playlist_id')
                    ->whereNull('groups.deleted_at');
                if ($type) {
                    $join->where('groups.type', '=', $type);
                }
            })
            ->when($includePlaylistName, fn ($query) => $query->leftJoin('playlists', 'playlists.id', '=', 'source_groups.playlist_id'))
            ->whereIn('source_groups.playlist_id', $playlistIds)
            ->when($type, fn ($query) => $query->where('source_groups.type', $type))
            ->whereIn('source_groups.id', $ids)
            ->selectRaw('source_groups.id as id, COALESCE(groups.name, source_groups.name) as label')
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
