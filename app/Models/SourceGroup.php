<?php

namespace App\Models;

use App\Traits\HasPlaylistScopedDisplayLabels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause;

class SourceGroup extends Model
{
    use HasPlaylistScopedDisplayLabels;

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
        $ids = self::numericIds($ids);
        $playlistIds = self::numericIds($playlistIds);
        if (empty($playlistIds) || empty($ids)) {
            return [];
        }

        $rows = static::query()
            ->leftJoin('groups', function (JoinClause $join) use ($type): void {
                $join->on('groups.name_internal', '=', 'source_groups.name')
                    ->on('groups.playlist_id', '=', 'source_groups.playlist_id')
                    ->whereNull('groups.deleted_at');
                if ($type) {
                    $join->where('groups.type', '=', $type);
                }
            })
            ->whereIn('source_groups.playlist_id', $playlistIds)
            ->when($type, fn ($query) => $query->where('source_groups.type', $type))
            ->whereIn('source_groups.id', $ids)
            ->selectRaw('source_groups.id as id, COALESCE(groups.name, source_groups.name) as label')
            ->when($includePlaylistName, fn ($query) => self::selectPlaylistName($query, 'source_groups'))
            ->get();

        return self::labelsById($rows);
    }
}
