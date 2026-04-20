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

        // Determine order by column, handling special cases.
        // IMPORTANT: $column is whitelisted here because its value is interpolated
        // directly into raw SQL below; never fall through unknown values.
        [$orderByColumn, $lowerOrderByColumn] = match ($column) {
            'title', null => ['COALESCE(title_custom, title)', 'LOWER(COALESCE(title_custom, title))'],
            'name' => ['COALESCE(name_custom, name)', 'LOWER(COALESCE(name_custom, name))'],
            'stream_id' => ['COALESCE(stream_id_custom, stream_id)', 'LOWER(COALESCE(stream_id_custom, stream_id))'],
            'channel' => ['channel', 'channel'],
            default => throw new \InvalidArgumentException('Invalid sort column provided.'),
        };

        // MySQL (8+)
        if ($driver === 'mysql') {
            DB::statement("UPDATE channels c JOIN (SELECT id, ROW_NUMBER() OVER (ORDER BY {$lowerOrderByColumn} {$direction}) AS rn FROM channels WHERE group_id = ?) t ON c.id = t.id SET c.sort = t.rn", [$record->id]);

            return;
        }

        // Postgres
        if (str_starts_with($driver, 'pgsql') || $driver === 'postgresql' || $driver === 'postgres') {
            DB::statement("UPDATE channels SET sort = t.rn FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY {$lowerOrderByColumn} {$direction}) AS rn FROM channels WHERE group_id = ?) t WHERE channels.id = t.id", [$record->id]);

            return;
        }

        // SQLite
        if ($driver === 'sqlite') {
            DB::statement("WITH ranked AS (SELECT id, ROW_NUMBER() OVER (ORDER BY {$lowerOrderByColumn} {$direction}) AS rn FROM channels WHERE group_id = ?) UPDATE channels SET sort = (SELECT rn FROM ranked WHERE ranked.id = channels.id) WHERE group_id = ?", [$record->id, $record->id]);

            return;
        }

        // Fallback: single CASE update
        $ids = $record->channels()->orderByRaw("{$lowerOrderByColumn} {$direction}")->pluck('id')->all();
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
        } elseif (str_starts_with($driver, 'pgsql') || $driver === 'postgresql' || $driver === 'postgres') {
            DB::statement('UPDATE channels SET channel = t.rn + ? FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY sort) AS rn FROM channels WHERE group_id = ?) t WHERE channels.id = t.id', [$offset, $record->id]);
        } elseif ($driver === 'sqlite') {
            DB::statement('WITH ranked AS (SELECT id, ROW_NUMBER() OVER (ORDER BY sort) AS rn FROM channels WHERE group_id = ?) UPDATE channels SET channel = (SELECT rn FROM ranked WHERE ranked.id = channels.id) + ? WHERE group_id = ?', [$record->id, $offset, $record->id]);
        } else {
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

        EpgCacheService::clearForGroup($record->id, $record->playlist_id);
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
        } elseif (str_starts_with($driver, 'pgsql') || $driver === 'postgresql' || $driver === 'postgres') {
            DB::statement("UPDATE channels SET channel = t.rn + ? FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY sort) AS rn FROM channels WHERE id IN ({$idsSql})) t WHERE channels.id = t.id", [$offset]);
        } elseif ($driver === 'sqlite') {
            DB::statement("WITH ranked AS (SELECT id, ROW_NUMBER() OVER (ORDER BY sort) AS rn FROM channels WHERE id IN ({$idsSql})) UPDATE channels SET channel = (SELECT rn FROM ranked WHERE ranked.id = channels.id) + ? WHERE id IN ({$idsSql})", [$offset]);
        } else {
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

        EpgCacheService::clearForChannelIds($ids);
    }

    /**
     * Sort channels INSIDE a CustomPlaylist only (pivot table),
     * without touching channels.sort (global).
     */
    public function bulkSortAlphaCustomPlaylistChannels(CustomPlaylist $playlist, Collection $channels, string $order = 'ASC', string $column = 'title'): void
    {
        $direction = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
        $driver = DB::getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        $lowerOrderByColumn = match ($column) {
            'title', null => 'LOWER(COALESCE(c.title_custom, c.title))',
            'name' => 'LOWER(COALESCE(c.name_custom, c.name))',
            'stream_id' => 'LOWER(COALESCE(c.stream_id_custom, c.stream_id))',
            'channel' => 'c.channel',
            default => throw new \InvalidArgumentException('Invalid sort column provided.'),
        };

        $ids = $channels->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        if (empty($ids)) {
            return;
        }

        $idsSql = implode(',', $ids);

        // MySQL (8+)
        if ($driver === 'mysql') {
            DB::statement(
                "UPDATE channel_custom_playlist ccp
                 JOIN (
                    SELECT ccp2.channel_id,
                           ROW_NUMBER() OVER (ORDER BY {$lowerOrderByColumn} {$direction}) AS rn
                    FROM channel_custom_playlist ccp2
                    JOIN channels c ON c.id = ccp2.channel_id
                    WHERE ccp2.custom_playlist_id = ?
                      AND ccp2.channel_id IN ({$idsSql})
                 ) t ON t.channel_id = ccp.channel_id
                 SET ccp.sort = t.rn
                 WHERE ccp.custom_playlist_id = ?
                   AND ccp.channel_id IN ({$idsSql})",
                [$playlist->id, $playlist->id]
            );
        } elseif (str_starts_with($driver, 'pgsql') || $driver === 'postgresql' || $driver === 'postgres') {
            // PostgreSQL
            DB::statement(
                "UPDATE channel_custom_playlist ccp
                 SET sort = t.rn
                 FROM (
                    SELECT ccp2.channel_id,
                           ROW_NUMBER() OVER (ORDER BY {$lowerOrderByColumn} {$direction}) AS rn
                    FROM channel_custom_playlist ccp2
                    JOIN channels c ON c.id = ccp2.channel_id
                    WHERE ccp2.custom_playlist_id = ?
                      AND ccp2.channel_id IN ({$idsSql})
                 ) t
                 WHERE ccp.custom_playlist_id = ?
                   AND ccp.channel_id = t.channel_id
                   AND ccp.channel_id IN ({$idsSql})",
                [$playlist->id, $playlist->id]
            );
        } elseif ($driver === 'sqlite') {
            // SQLite
            DB::statement(
                "WITH ranked AS (
                    SELECT ccp2.channel_id,
                           ROW_NUMBER() OVER (ORDER BY {$lowerOrderByColumn} {$direction}) AS rn
                    FROM channel_custom_playlist ccp2
                    JOIN channels c ON c.id = ccp2.channel_id
                    WHERE ccp2.custom_playlist_id = ?
                      AND ccp2.channel_id IN ({$idsSql})
                 )
                 UPDATE channel_custom_playlist
                 SET sort = (SELECT rn FROM ranked WHERE ranked.channel_id = channel_custom_playlist.channel_id)
                 WHERE custom_playlist_id = ?
                   AND channel_id IN ({$idsSql})",
                [$playlist->id, $playlist->id]
            );
        } else {
            // Fallback: CASE update
            $orderedIds = DB::table('channel_custom_playlist as ccp')
                ->join('channels as c', 'c.id', '=', 'ccp.channel_id')
                ->where('ccp.custom_playlist_id', $playlist->id)
                ->whereIn('ccp.channel_id', $ids)
                ->orderByRaw("{$lowerOrderByColumn} {$direction}")
                ->pluck('ccp.channel_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (empty($orderedIds)) {
                return;
            }

            $cases = [];
            foreach ($orderedIds as $i => $id) {
                $cases[] = "WHEN {$id} THEN ".($i + 1);
            }

            $casesSql = implode(' ', $cases);
            $orderedIdsSql = implode(',', $orderedIds);

            DB::statement(
                "UPDATE channel_custom_playlist
                 SET sort = CASE channel_id {$casesSql} END
                 WHERE custom_playlist_id = {$playlist->id}
                   AND channel_id IN ({$orderedIdsSql})"
            );
        }

        EpgCacheService::clearForCustomPlaylistId($playlist->id);
    }

    /**
     * Recount channel numbers INSIDE a CustomPlaylist only (pivot table),
     * without touching channels.channel (global).
     */
    public function bulkRecountCustomPlaylistChannels(CustomPlaylist $playlist, Collection $channels, int $start = 1): void
    {
        $offset = max(0, $start - 1);
        $driver = DB::getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        $ids = $channels->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        if (empty($ids)) {
            return;
        }

        $idsSql = implode(',', $ids);

        // MySQL (8+)
        if ($driver === 'mysql') {
            DB::statement(
                "UPDATE channel_custom_playlist ccp
                 JOIN (
                    SELECT ccp2.channel_id,
                           ROW_NUMBER() OVER (ORDER BY c.sort, c.channel, c.id) AS rn
                    FROM channel_custom_playlist ccp2
                    JOIN channels c ON c.id = ccp2.channel_id
                    WHERE ccp2.custom_playlist_id = ?
                      AND ccp2.channel_id IN ({$idsSql})
                 ) t ON t.channel_id = ccp.channel_id
                 SET ccp.channel_number = t.rn + ?
                 WHERE ccp.custom_playlist_id = ?
                   AND ccp.channel_id IN ({$idsSql})",
                [$playlist->id, $offset, $playlist->id]
            );
        } elseif (str_starts_with($driver, 'pgsql') || $driver === 'postgresql' || $driver === 'postgres') {
            // Postgres
            DB::statement(
                "UPDATE channel_custom_playlist ccp
                 SET channel_number = t.rn + ?
                 FROM (
                    SELECT ccp2.channel_id,
                           ROW_NUMBER() OVER (ORDER BY c.sort, c.channel, c.id) AS rn
                    FROM channel_custom_playlist ccp2
                    JOIN channels c ON c.id = ccp2.channel_id
                    WHERE ccp2.custom_playlist_id = ?
                      AND ccp2.channel_id IN ({$idsSql})
                 ) t
                 WHERE ccp.custom_playlist_id = ?
                   AND ccp.channel_id = t.channel_id
                   AND ccp.channel_id IN ({$idsSql})",
                [$offset, $playlist->id, $playlist->id]
            );
        } elseif ($driver === 'sqlite') {
            // SQLite
            DB::statement(
                "WITH ranked AS (
                    SELECT ccp2.channel_id AS channel_id,
                           ROW_NUMBER() OVER (ORDER BY c.sort, c.channel, c.id) AS rn
                    FROM channel_custom_playlist ccp2
                    JOIN channels c ON c.id = ccp2.channel_id
                    WHERE ccp2.custom_playlist_id = ?
                      AND ccp2.channel_id IN ({$idsSql})
                 )
                 UPDATE channel_custom_playlist
                 SET channel_number = (SELECT rn FROM ranked WHERE ranked.channel_id = channel_custom_playlist.channel_id) + ?
                 WHERE custom_playlist_id = ?
                   AND channel_id IN ({$idsSql})",
                [$playlist->id, $offset, $playlist->id]
            );
        } else {
            // Fallback: CASE update (other DB drivers)
            $orderedIds = DB::table('channel_custom_playlist as ccp')
                ->join('channels as c', 'c.id', '=', 'ccp.channel_id')
                ->where('ccp.custom_playlist_id', $playlist->id)
                ->whereIn('ccp.channel_id', $ids)
                ->orderBy('c.sort')
                ->orderBy('c.channel')
                ->orderBy('c.id')
                ->pluck('ccp.channel_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (empty($orderedIds)) {
                return;
            }

            $cases = [];
            $i = $start;
            foreach ($orderedIds as $id) {
                $cases[] = "WHEN {$id} THEN {$i}";
                $i++;
            }

            $casesSql = implode(' ', $cases);
            $orderedIdsSql = implode(',', $orderedIds);

            DB::statement(
                "UPDATE channel_custom_playlist
                 SET channel_number = CASE channel_id {$casesSql} END
                 WHERE custom_playlist_id = {$playlist->id}
                   AND channel_id IN ({$orderedIdsSql})"
            );
        }

        EpgCacheService::clearForCustomPlaylistId($playlist->id);
    }
}
