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
     * imported, otherwise falls back to the source group's own name.
     *
     * @param  array<int|string>  $ids
     * @return array<int, string> source group id => display name
     */
    public static function displayLabelsForIds(?int $playlistId, ?string $type, array $ids): array
    {
        $ids = array_values(array_filter($ids, fn ($value): bool => is_numeric($value)));
        if (! $playlistId || empty($ids)) {
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
            ->where('source_groups.playlist_id', $playlistId)
            ->when($type, fn ($query) => $query->where('source_groups.type', $type))
            ->whereIn('source_groups.id', $ids)
            ->selectRaw('source_groups.id as id, COALESCE(groups.name, source_groups.name) as label')
            ->get()
            ->pluck('label', 'id')
            ->toArray();
    }
}
