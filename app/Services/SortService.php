<?php

namespace App\Services;

use App\Models\CustomPlaylist;
use App\Models\Group;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SortService
{
    /**
     * Bulk-update channels' sort order using DB window functions when available,
     * falling back to a single CASE-based UPDATE to avoid N queries.
     */
    public function bulkSortGroupChannels(Group $record, string $order = 'ASC', ?string $column = 'title'): void
    {
        $direction = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
        $driver = DB::getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        // Determine order by column, handling special cases
        $orderByColumn = match ($column) {
            'title' => 'COALESCE(title_custom, title)',
            'name' => 'COALESCE(name_custom, name)',
            'stream_id' => 'COALESCE(stream_id_custom, stream_id)',
            default => $column,
        };

        // MySQL (8+)
        if ($driver === 'mysql') {
            DB::statement("UPDATE channels c JOIN (SELECT id, ROW_NUMBER() OVER (ORDER BY COALESCE(title_custom, title) {$direction}) AS rn FROM channels WHERE group_id = ?) t ON c.id = t.id SET c.sort = t.rn", [$record->id]);

            return;
        }

        // Postgres
        if (str_starts_with($driver, 'pgsql') || $driver === 'postgresql' || $driver === 'postgres') {
            DB::statement("UPDATE channels SET sort = t.rn FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY {$orderByColumn} {$direction}) AS rn FROM channels WHERE group_id = ?) t WHERE channels.id = t.id", [$record->id]);

            return;
        }

        // SQLite
        if ($driver === 'sqlite') {
            DB::statement("WITH ranked AS (SELECT id, ROW_NUMBER() OVER (ORDER BY {$orderByColumn} {$direction}) AS rn FROM channels WHERE group_id = ?) UPDATE channels SET sort = (SELECT rn FROM ranked WHERE ranked.id = channels.id) WHERE group_id = ?", [$record->id, $record->id]);

            return;
        }

        // Fallback: single CASE update
        $ids = $record->channels()->orderByRaw("{$orderByColumn} {$order}")->pluck('id')->all();
        if (empty($ids)) {
            return;
        }

        $cases = [];
        $i = 1;
        foreach ($ids as $id) {
            $cases[] = "WHEN {$id} THEN {$i}";
            $i++;
        }

        $casesSql = implode(' ', $cases);
        $idsSql = implode(',', $ids);

        DB::statement("UPDATE channels SET sort = CASE id {$casesSql} END WHERE id IN ({$idsSql})");
    }

    /**
     * Bulk recount channel numbers.
     */
    public function bulkRecountGroupChannels(Group $record, int $start = 1): void
    {
        $offset = max(0, $start - 1);
        $driver = DB::getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            DB::statement('UPDATE channels c JOIN (SELECT id, ROW_NUMBER() OVER (ORDER BY sort) AS rn FROM channels WHERE group_id = ?) t ON c.id = t.id SET c.channel = t.rn + ?', [$record->id, $offset]);

            return;
        }

        if (str_starts_with($driver, 'pgsql') || $driver === 'postgresql' || $driver === 'postgres') {
            DB::statement('UPDATE channels SET channel = t.rn + ? FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY sort) AS rn FROM channels WHERE group_id = ?) t WHERE channels.id = t.id', [$offset, $record->id]);

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('WITH ranked AS (SELECT id, ROW_NUMBER() OVER (ORDER BY sort) AS rn FROM channels WHERE group_id = ?) UPDATE channels SET channel = (SELECT rn FROM ranked WHERE ranked.id = channels.id) + ? WHERE group_id = ?', [$record->id, $offset, $record->id]);

            return;
        }

        // Fallback: CASE update
        $ids = $record->channels()->orderBy('sort')->pluck('id')->all();
        if (empty($ids)) {
            return;
        }

        $cases = [];
        $i = $start;
        foreach ($ids as $id) {
            $cases[] = "WHEN {$id} THEN {$i}";
            $i++;
        }

        $casesSql = implode(' ', $cases);
        $idsSql = implode(',', $ids);

        DB::statement("UPDATE channels SET channel = CASE id {$casesSql} END WHERE id IN ({$idsSql})");
    }

    public function bulkRecountChannels(Collection $channels, $start = 1): void
    {
        $offset = max(0, $start - 1);
        $driver = DB::getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        $ids = $channels->sortBy('sort')->pluck('id')->all();
        if (empty($ids)) {
            return;
        }

        $ids = array_map('intval', $ids);
        $idsSql = implode(',', $ids);

        if ($driver === 'mysql') {
            DB::statement("UPDATE channels c JOIN (SELECT id, ROW_NUMBER() OVER (ORDER BY sort) AS rn FROM channels WHERE id IN ({$idsSql})) t ON c.id = t.id SET c.channel = t.rn + ?", [$offset]);

            return;
        }

        if (str_starts_with($driver, 'pgsql') || $driver === 'postgresql' || $driver === 'postgres') {
            DB::statement("UPDATE channels SET channel = t.rn + ? FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY sort) AS rn FROM channels WHERE id IN ({$idsSql})) t WHERE channels.id = t.id", [$offset]);

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement("WITH ranked AS (SELECT id, ROW_NUMBER() OVER (ORDER BY sort) AS rn FROM channels WHERE id IN ({$idsSql})) UPDATE channels SET channel = (SELECT rn FROM ranked WHERE ranked.id = channels.id) + ? WHERE id IN ({$idsSql})", [$offset]);

            return;
        }

        // Fallback: CASE update
        $cases = [];
        $i = $start;
        foreach ($ids as $id) {
            $cases[] = "WHEN {$id} THEN {$i}";
            $i++;
        }

        $casesSql = implode(' ', $cases);

        DB::statement("UPDATE channels SET channel = CASE id {$casesSql} END WHERE id IN ({$idsSql})");
    }

    public function bulkRecountCustomPlaylistChannels(CustomPlaylist $playlist, int $start = 1): void
    {
        $offset = max(0, $start - 1);
        $driver = DB::getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            DB::statement(
                'UPDATE channel_custom_playlist ccp
                 JOIN (
                    SELECT channel_id, custom_playlist_id,
                           ROW_NUMBER() OVER (ORDER BY sort, channel_id) AS rn
                    FROM channel_custom_playlist
                    WHERE custom_playlist_id = ?
                 ) t
                   ON ccp.channel_id = t.channel_id
                  AND ccp.custom_playlist_id = t.custom_playlist_id
                 SET ccp.channel_number = t.rn + ?
                 WHERE ccp.custom_playlist_id = ?',
                [$playlist->id, $offset, $playlist->id]
            );

            return;
        }

        if (str_contains($driver, 'pgsql') || $driver === 'postgres') {
            DB::statement(
                'UPDATE channel_custom_playlist ccp
                 SET channel_number = t.rn + ?
                 FROM (
                    SELECT channel_id, custom_playlist_id,
                           ROW_NUMBER() OVER (ORDER BY sort, channel_id) AS rn
                    FROM channel_custom_playlist
                    WHERE custom_playlist_id = ?
                 ) t
                 WHERE ccp.channel_id = t.channel_id
                   AND ccp.custom_playlist_id = t.custom_playlist_id',
                [$offset, $playlist->id]
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement(
                'WITH ranked AS (
                    SELECT channel_id, custom_playlist_id,
                           ROW_NUMBER() OVER (ORDER BY sort, channel_id) AS rn
                    FROM channel_custom_playlist
                    WHERE custom_playlist_id = ?
                 )
                 UPDATE channel_custom_playlist
                 SET channel_number = (SELECT rn FROM ranked
                                       WHERE ranked.channel_id = channel_custom_playlist.channel_id
                                         AND ranked.custom_playlist_id = channel_custom_playlist.custom_playlist_id) + ?
                 WHERE custom_playlist_id = ?',
                [$playlist->id, $offset, $playlist->id]
            );
        }
    }
}
