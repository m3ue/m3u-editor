<?php

namespace App\Services;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Single-file SQLite store for an EPG's SchedulesDirect source data, so a sync
 * only downloads the lineups, station-days and programs whose modified stamp or
 * MD5 changed since the last one.
 *
 * Like {@see EpgProgrammeStore}, this lives outside the app database: it holds
 * a large number of frequently replaced rows. Unlike that store it is updated in
 * place rather than rebuilt, because keeping it between syncs is the point. It
 * is disposable: a file that can't be opened, or was written with a different
 * {@see SCHEMA_VERSION}, is deleted and started again, which only costs one full
 * download. Payloads are stored as deflated JSON, which shrinks them two to
 * four times; they are only ever read whole.
 */
class SchedulesDirectCacheStore
{
    /**
     * Bump when the schema or the cached program fields change, so existing
     * cache files are discarded instead of serving rows missing the new data.
     */
    public const SCHEMA_VERSION = 2;

    /** Bound parameters per `IN (...)` list. */
    private const MAX_IN_PARAMS = 500;

    private PDO $pdo;

    /** The generation of the sync using this store; rows not touched by it are pruned when it finishes. */
    private int $syncGeneration = 0;

    private function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Open (creating if needed) the cache file at $sqlitePath.
     */
    public static function open(string $sqlitePath): self
    {
        $directory = dirname($sqlitePath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        try {
            return self::connect($sqlitePath);
        } catch (PDOException) {
            // Unreadable or corrupt: it is only a cache, so start a new one
            self::deleteFiles($sqlitePath);

            return self::connect($sqlitePath);
        }
    }

    private static function connect(string $sqlitePath): self
    {
        $pdo = self::newPdo($sqlitePath);
        if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() !== self::SCHEMA_VERSION) {
            $pdo = null;
            self::deleteFiles($sqlitePath);
            $pdo = self::newPdo($sqlitePath);
            self::createSchema($pdo);
        }

        return new self($pdo);
    }

    private static function newPdo(string $sqlitePath): PDO
    {
        $pdo = new PDO('sqlite:'.$sqlitePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec('PRAGMA temp_store=MEMORY');

        return $pdo;
    }

    private static function createSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE lineups (lineup_id TEXT PRIMARY KEY, modified TEXT NOT NULL, data BLOB NOT NULL)');
        $pdo->exec(
            'CREATE TABLE schedules ('
            .'station_id TEXT NOT NULL, '
            .'date TEXT NOT NULL, '
            .'md5 TEXT NOT NULL, '
            .'data BLOB NOT NULL, '
            .'PRIMARY KEY (station_id, date))'
        );
        $pdo->exec(
            'CREATE TABLE programs ('
            .'program_id TEXT PRIMARY KEY, '
            .'md5 TEXT NOT NULL, '
            .'data BLOB NOT NULL, '
            .'artwork BLOB, '
            .'artwork_fetched_at INTEGER, '
            .'last_used_sync INTEGER NOT NULL)'
        );
        $pdo->exec('CREATE INDEX programs_last_used_sync ON programs (last_used_sync)');
        $pdo->exec('PRAGMA user_version='.self::SCHEMA_VERSION);
    }

    private static function deleteFiles(string $sqlitePath): void
    {
        foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
            if (is_file($sqlitePath.$suffix)) {
                @unlink($sqlitePath.$suffix);
            }
        }
    }

    public function close(): void
    {
        unset($this->pdo);
    }

    /**
     * Start a sync: every program it writes or touches is marked with a new
     * generation, and {@see pruneUnusedPrograms()} drops the rest.
     */
    public function beginSync(): void
    {
        $generation = (int) $this->pdo->query("SELECT value FROM meta WHERE key = 'sync_generation'")->fetchColumn() + 1;
        $this->pdo->prepare("INSERT INTO meta (key, value) VALUES ('sync_generation', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")
            ->execute([(string) $generation]);
        $this->syncGeneration = $generation;
    }

    /**
     * The cached lineup, when it was stored with the same modified stamp.
     *
     * @return array<string, mixed>|null
     */
    public function lineup(string $lineupId, string $modified): ?array
    {
        $statement = $this->pdo->prepare('SELECT data FROM lineups WHERE lineup_id = ? AND modified = ?');
        $statement->execute([$lineupId, $modified]);
        $data = $statement->fetchColumn();
        $lineup = $data === false ? null : json_decode(self::decode($data), true);

        return is_array($lineup) ? $lineup : null;
    }

    /**
     * @param  array<string, mixed>  $lineup
     */
    public function putLineup(string $lineupId, string $modified, array $lineup): void
    {
        self::execute($this->pdo->prepare('INSERT OR REPLACE INTO lineups (lineup_id, modified, data) VALUES (?, ?, ?)'), [$lineupId, $modified, self::encode($lineup)], [2]);
    }

    /**
     * The cached MD5 of each station-day, keyed by station ID then date.
     *
     * @param  array<int, string>  $stationIds
     * @param  array<int, string>  $dates
     * @return array<string, array<string, string>>
     */
    public function scheduleMd5s(array $stationIds, array $dates): array
    {
        $md5s = [];
        foreach ($this->selectStationDays('station_id, date, md5', $stationIds, $dates) as $row) {
            $md5s[$row['station_id']][$row['date']] = $row['md5'];
        }

        return $md5s;
    }

    /**
     * The cached schedules of the given station-days.
     *
     * @param  array<string, array<int, string>>  $datesByStation  dates keyed by station ID
     * @return array<int, array<string, mixed>>
     */
    public function schedules(array $datesByStation): array
    {
        $allDates = array_values(array_unique(array_merge(...array_values($datesByStation))));
        $schedules = [];
        foreach ($this->selectStationDays('station_id, date, data', array_map('strval', array_keys($datesByStation)), $allDates) as $row) {
            if (in_array($row['date'], $datesByStation[$row['station_id']] ?? [], true)) {
                $schedule = json_decode(self::decode($row['data']), true);
                if (is_array($schedule)) {
                    $schedules[] = $schedule;
                }
            }
        }

        return $schedules;
    }

    /**
     * @param  array<int, string>  $stationIds
     * @param  array<int, string>  $dates
     * @return array<int, array<string, string>>
     */
    private function selectStationDays(string $columns, array $stationIds, array $dates): array
    {
        if ($stationIds === [] || $dates === []) {
            return [];
        }

        $rows = [];
        $datePlaceholders = self::placeholders($dates);
        foreach (array_chunk($stationIds, self::MAX_IN_PARAMS) as $chunk) {
            $statement = $this->pdo->prepare(
                "SELECT {$columns} FROM schedules WHERE station_id IN (".self::placeholders($chunk).") AND date IN ({$datePlaceholders})"
            );
            $statement->execute([...$chunk, ...$dates]);
            array_push($rows, ...$statement->fetchAll());
        }

        return $rows;
    }

    /**
     * @param  array<int, array{station_id: string, date: string, md5: string, schedule: array<string, mixed>}>  $rows
     */
    public function putSchedules(array $rows): void
    {
        $statement = $this->pdo->prepare('INSERT OR REPLACE INTO schedules (station_id, date, md5, data) VALUES (?, ?, ?, ?)');
        $this->transaction(function () use ($statement, $rows) {
            foreach ($rows as $row) {
                self::execute($statement, [$row['station_id'], $row['date'], $row['md5'], self::encode($row['schedule'])], [3]);
            }
        });
    }

    /**
     * The cached MD5 of each program, keyed by programID.
     *
     * @param  array<int, string>  $programIds
     * @return array<string, string>
     */
    public function programMd5s(array $programIds): array
    {
        $md5s = [];
        foreach (array_chunk($programIds, self::MAX_IN_PARAMS) as $chunk) {
            $statement = $this->pdo->prepare('SELECT program_id, md5 FROM programs WHERE program_id IN ('.self::placeholders($chunk).')');
            $statement->execute($chunk);
            foreach ($statement->fetchAll() as $row) {
                $md5s[$row['program_id']] = $row['md5'];
            }
        }

        return $md5s;
    }

    /**
     * Insert or replace programs. A replaced program keeps its cached artwork:
     * artwork is not covered by the program MD5 and is refreshed on its own
     * schedule (see SchedulesDirectService::ARTWORK_REFRESH_DAYS).
     *
     * @param  array<int, array{program_id: string, md5: string, data: array<string, mixed>}>  $rows
     */
    public function putPrograms(array $rows): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO programs (program_id, md5, data, last_used_sync) VALUES (?, ?, ?, ?) '
            .'ON CONFLICT(program_id) DO UPDATE SET md5 = excluded.md5, data = excluded.data, last_used_sync = excluded.last_used_sync'
        );
        $this->transaction(function () use ($statement, $rows) {
            foreach ($rows as $row) {
                self::execute($statement, [$row['program_id'], $row['md5'], self::encode($row['data']), $this->syncGeneration], [2]);
            }
        });
    }

    /**
     * The given programs whose artwork was never fetched, was fetched before
     * $fetchedBefore, or had none when last checked before $missingCheckedBefore.
     *
     * @param  array<int, string>  $programIds
     * @return array<int, string>
     */
    public function programsNeedingArtwork(array $programIds, int $fetchedBefore, int $missingCheckedBefore): array
    {
        $needing = [];
        foreach (array_chunk($programIds, self::MAX_IN_PARAMS) as $chunk) {
            $statement = $this->pdo->prepare(
                'SELECT program_id FROM programs WHERE program_id IN ('.self::placeholders($chunk).') AND (artwork_fetched_at IS NULL OR artwork_fetched_at < ? OR (artwork IS NULL AND artwork_fetched_at < ?))'
            );
            $statement->execute([...$chunk, $fetchedBefore, $missingCheckedBefore]);
            array_push($needing, ...$statement->fetchAll(PDO::FETCH_COLUMN));
        }

        return $needing;
    }

    /**
     * Store the fetched artwork of each program; programs with no entry in
     * $artworkByProgramId are recorded as having none.
     *
     * @param  array<int, string>  $programIds
     * @param  array<string, array<int, array<string, mixed>>>  $artworkByProgramId
     */
    public function putArtwork(array $programIds, array $artworkByProgramId, int $fetchedAt): void
    {
        $statement = $this->pdo->prepare('UPDATE programs SET artwork = ?, artwork_fetched_at = ? WHERE program_id = ?');
        $this->transaction(function () use ($statement, $programIds, $artworkByProgramId, $fetchedAt) {
            foreach ($programIds as $programId) {
                $artwork = isset($artworkByProgramId[$programId]) ? self::encode($artworkByProgramId[$programId]) : null;
                self::execute($statement, [$artwork, $fetchedAt, $programId], [0]);
            }
        });
    }

    /**
     * The cached data and artwork JSON of the given programs, marking each as
     * used by the current sync.
     *
     * @param  array<int, string>  $programIds
     * @return array<int, array{program_id: string, data: string, artwork: ?string}>
     */
    public function usePrograms(array $programIds): array
    {
        $rows = [];
        $this->transaction(function () use ($programIds, &$rows) {
            foreach (array_chunk($programIds, self::MAX_IN_PARAMS) as $chunk) {
                $placeholders = self::placeholders($chunk);
                $this->pdo->prepare("UPDATE programs SET last_used_sync = ? WHERE program_id IN ({$placeholders})")
                    ->execute([$this->syncGeneration, ...$chunk]);
                $statement = $this->pdo->prepare("SELECT program_id, data, artwork FROM programs WHERE program_id IN ({$placeholders})");
                $statement->execute($chunk);
                foreach ($statement->fetchAll() as $row) {
                    $rows[] = [
                        'program_id' => $row['program_id'],
                        'data' => self::decode($row['data']),
                        'artwork' => $row['artwork'] === null ? null : self::decode($row['artwork']),
                    ];
                }
            }
        });

        return $rows;
    }

    /**
     * Drop cached data a successful sync no longer needs: station-days before
     * $firstDate, stations and lineups no longer selected, and programs the
     * current sync never used.
     *
     * @param  array<int, string>  $stationIds
     * @param  array<int, string>  $lineupIds
     */
    public function prune(string $firstDate, array $stationIds, array $lineupIds): void
    {
        $this->transaction(function () use ($firstDate, $stationIds, $lineupIds) {
            $this->pdo->prepare('DELETE FROM schedules WHERE date < ?')->execute([$firstDate]);

            $keepStations = array_flip(array_map('strval', $stationIds));
            $removedStations = array_values(array_filter(
                $this->pdo->query('SELECT DISTINCT station_id FROM schedules')->fetchAll(PDO::FETCH_COLUMN),
                fn (string $stationId) => ! isset($keepStations[$stationId]),
            ));
            foreach (array_chunk($removedStations, self::MAX_IN_PARAMS) as $chunk) {
                $this->pdo->prepare('DELETE FROM schedules WHERE station_id IN ('.self::placeholders($chunk).')')->execute($chunk);
            }

            if ($lineupIds === []) {
                $this->pdo->exec('DELETE FROM lineups');
            } else {
                $this->pdo->prepare('DELETE FROM lineups WHERE lineup_id NOT IN ('.self::placeholders($lineupIds).')')->execute(array_values($lineupIds));
            }

            $this->pdo->prepare('DELETE FROM programs WHERE last_used_sync < ?')->execute([$this->syncGeneration]);
        });
    }

    private function transaction(callable $callback): void
    {
        $this->pdo->beginTransaction();
        try {
            $callback();
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param  array<mixed>  $values
     */
    private static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    /**
     * Execute a statement, binding the values at $blobPositions (deflated
     * payloads) as BLOBs.
     *
     * @param  array<int, mixed>  $values
     * @param  array<int, int>  $blobPositions  zero-based indexes into $values
     */
    private static function execute(PDOStatement $statement, array $values, array $blobPositions): void
    {
        foreach ($values as $index => $value) {
            $statement->bindValue($index + 1, $value, match (true) {
                $value === null => PDO::PARAM_NULL,
                in_array($index, $blobPositions, true) => PDO::PARAM_LOB,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            });
        }
        $statement->execute();
    }

    /**
     * @param  array<mixed>  $value
     */
    private static function encode(array $value): string
    {
        return gzdeflate(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}', 6);
    }

    private static function decode(string $payload): string
    {
        return @gzinflate($payload) ?: '{}';
    }
}
