<?php

namespace App\Services;

use Generator;
use PDO;
use Pdo\Sqlite;

/**
 * Single-file SQLite store for an EPG's cached programmes.
 *
 * Replaces the previous per-date `programmes-{date}.jsonl` files and their
 * hand-rolled `.index.json` byte-offset index. One `programmes.sqlite` per EPG
 * cache dir holds every programme; a B-tree index on
 * `(date, channel_id, start_ts)` gives seek-free reads for the handful of
 * channels a request actually asks for.
 *
 * This class is the single source of truth for the on-disk programme format:
 * `channel` / `start` / `stop` live in real columns, and the rest of the
 * payload is stored as JSON with keys left at their canonical empty default
 * ({@see EMPTY_PROGRAMME}) stripped. {@see hydrate()} rebuilds the exact array
 * shape callers of {@see EpgCacheService} have always received.
 */
class EpgProgrammeStore
{
    /**
     * Canonical programme array shape. Mirrors what the XMLTV parser in
     * {@see EpgCacheService::parseAndSaveEpgDataSinglePass()} builds per
     * `<programme>`; readers always get every key back via {@see hydrate()}.
     *
     * @var array<string, mixed>
     */
    public const EMPTY_PROGRAMME = [
        'channel' => '',
        'start' => null,
        'stop' => null,
        'title' => '',
        'subtitle' => '',
        'desc' => '',
        'category' => '',
        'episode_num' => '',
        'episode_nums' => [],
        'rating' => '',
        'icon' => '',
        'images' => [],
        'new' => false,
        'previously_shown' => false,
        'premiere' => false,
        'urls' => [],
        'production_year' => null,
    ];

    /** Rows per write transaction. Large enough to amortize the commit, small enough to bound WAL-less memory. */
    private const COMMIT_EVERY = 2000;

    /** Above this many requested channels, a single date scan + PHP-side filter beats a huge `IN (...)` list. */
    private const MAX_IN_PARAMS = 500;

    private ?PDO $pdo = null;

    private ?\PDOStatement $insertStatement = null;

    private int $pendingRows = 0;

    private string $buildingPath = '';

    private string $finalPath = '';

    /** Inode of the file {@see openExisting()} opened, to detect a rebuild swapping it out. */
    private int|false $openedInode = false;

    /**
     * Strip `channel` / `start` / `stop` (stored as columns) and any key still
     * at its canonical default, so the JSON blob carries only real data.
     *
     * @param  array<string, mixed>  $programme
     * @return array<string, mixed>
     */
    public static function dehydrate(array $programme): array
    {
        $out = [];
        foreach ($programme as $key => $value) {
            if ($key === 'channel' || $key === 'start' || $key === 'stop') {
                continue;
            }
            if (array_key_exists($key, self::EMPTY_PROGRAMME) && $value === self::EMPTY_PROGRAMME[$key]) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Rebuild the full canonical programme array from a stored blob plus its
     * column values. `start` / `stop` are re-rendered as UTC ISO-8601 strings
     * (microsecond form, matching Carbon's `toISOString()`) from the unix
     * timestamps so every downstream `Carbon::parse()` keeps working.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public static function hydrate(array $stored, string $channelId, ?int $startTs, ?int $stopTs): array
    {
        $programme = array_replace(self::EMPTY_PROGRAMME, $stored);
        $programme['channel'] = $channelId;
        $programme['start'] = $startTs !== null ? self::timestampToIso($startTs) : null;
        $programme['stop'] = $stopTs !== null ? self::timestampToIso($stopTs) : null;

        return $programme;
    }

    private static function timestampToIso(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s', $timestamp).'.000000Z';
    }

    /**
     * Open a fresh writer. Builds into a `.building` sidecar and only renames it
     * over the real path in {@see finish()}, so readers never see a partial DB.
     */
    public function beginWrite(string $sqlitePath): void
    {
        $this->finalPath = $sqlitePath;
        // Unique per-run suffix so an overlapping rebuild of the same EPG cannot
        // write into the sidecar this run is mid-transaction on (journal_mode is
        // OFF). Whichever run calls finish() last wins the atomic rename.
        $this->buildingPath = $sqlitePath.'.building-'.getmypid().'-'.bin2hex(random_bytes(4));

        $directory = dirname($sqlitePath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        if (is_file($this->buildingPath)) {
            @unlink($this->buildingPath);
        }

        $this->pdo = new PDO('sqlite:'.$this->buildingPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // The DB is disposable and rebuilt from XML, and this run is the sole
        // writer, so durability pragmas are pure overhead here.
        $this->pdo->exec('PRAGMA journal_mode=OFF');
        $this->pdo->exec('PRAGMA synchronous=OFF');
        $this->pdo->exec('PRAGMA temp_store=MEMORY');
        $this->pdo->exec('PRAGMA cache_size=-8000');
        $this->pdo->exec(
            'CREATE TABLE programmes ('
            .'channel_id TEXT NOT NULL, '
            .'date TEXT NOT NULL, '
            .'start_ts INTEGER NOT NULL, '
            .'stop_ts INTEGER, '
            .'data TEXT NOT NULL)'
        );

        $this->insertStatement = $this->pdo->prepare(
            'INSERT INTO programmes (channel_id, date, start_ts, stop_ts, data) VALUES (?, ?, ?, ?, ?)'
        );
        $this->pdo->beginTransaction();
        $this->pendingRows = 0;
    }

    /**
     * Append one programme. `$date` is the local `Y-m-d` bucket (unchanged from
     * the JSONL scheme); `$startTs` / `$stopTs` are unix seconds.
     *
     * @param  array<string, mixed>  $programme
     */
    public function insert(string $channelId, string $date, int $startTs, ?int $stopTs, array $programme): void
    {
        // Substitute invalid UTF-8 rather than letting json_encode() return
        // false: a false blob would bind as '' and silently drop the whole
        // payload (title, desc, ...) for that programme on read.
        $blob = json_encode(self::dehydrate($programme), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($blob === false) {
            $blob = '{}';
        }
        $this->insertStatement->execute([$channelId, $date, $startTs, $stopTs, $blob]);

        if (++$this->pendingRows >= self::COMMIT_EVERY) {
            $this->pdo->commit();
            $this->pdo->beginTransaction();
            $this->pendingRows = 0;
        }
    }

    /**
     * Commit, build the lookup index, close, and atomically swap the built DB
     * into place. Throws if the swap fails so the caller can flag the cache
     * generation as failed rather than leaving a stale/missing store behind a
     * "success" metadata write.
     */
    public function finish(): void
    {
        if ($this->pdo === null) {
            return;
        }

        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
        $this->pdo->exec('CREATE INDEX programmes_date_channel ON programmes (date, channel_id, start_ts)');
        $this->insertStatement = null;
        $this->pdo = null;

        if (! @rename($this->buildingPath, $this->finalPath)) {
            @unlink($this->buildingPath);

            throw new \RuntimeException("Failed to move EPG programme store into place at {$this->finalPath}");
        }
    }

    /**
     * Abandon a half-written DB (parse failed). Leaves any existing real file
     * untouched.
     */
    public function discard(): void
    {
        $this->insertStatement = null;
        $this->pdo = null;
        if ($this->buildingPath !== '' && is_file($this->buildingPath)) {
            @unlink($this->buildingPath);
        }
    }

    /**
     * Open an existing store for reading. Caller is responsible for checking the
     * file exists first and for {@see close()}ing when done.
     */
    public static function openRead(string $sqlitePath): self
    {
        $store = new self;
        $store->pdo = new PDO('sqlite:'.$sqlitePath);
        $store->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $store;
    }

    /**
     * Open an existing store for the enrichment API (paged reads and in-place
     * row updates).
     *
     * Opened read-write WITHOUT the create flag, so a store that vanished
     * (a rebuild's clearCache() ran after the caller's existence check) fails to
     * open instead of leaving an empty database file behind. The build's
     * `journal_mode=OFF` is not persisted, so updates use SQLite's default
     * rollback journal: no -wal/-shm sidecars, and the journal only exists for
     * the length of a commit.
     *
     * @throws \PDOException when the store does not exist or cannot be opened
     */
    public static function openExisting(string $sqlitePath, int $busyTimeoutMs = 5000): self
    {
        $store = new self;
        $store->finalPath = $sqlitePath;
        $store->pdo = new PDO('sqlite:'.$sqlitePath, null, null, [
            Sqlite::ATTR_OPEN_FLAGS => Sqlite::OPEN_READWRITE,
        ]);
        $store->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $store->pdo->exec('PRAGMA busy_timeout='.(int) $busyTimeoutMs);
        clearstatcache(true, $sqlitePath);
        $store->openedInode = @fileinode($sqlitePath);

        return $store;
    }

    public function close(): void
    {
        $this->insertStatement = null;
        $this->pdo = null;
    }

    /**
     * Content hash of one stored row, used for optimistic concurrency by the
     * enrichment API. Covers the columns and the raw blob, so any change to the
     * row (another enrichment, or a rebuild reusing the rowid for a different
     * programme) changes the hash.
     *
     * @param  array{channel_id: string, start_ts: int|string|null, stop_ts: int|string|null, data: string}  $row
     */
    public static function rowHash(array $row): string
    {
        return hash('sha256', json_encode([
            $row['channel_id'],
            $row['start_ts'] !== null ? (int) $row['start_ts'] : null,
            $row['stop_ts'] !== null ? (int) $row['stop_ts'] : null,
            $row['data'],
        ]));
    }

    /**
     * One page of programmes ordered by rowid, as `rowid => [hash, programme]`.
     *
     * @return array<int, array{hash: string, programme: array<string, mixed>}>
     */
    public function readPage(int $afterRowid, int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT rowid, channel_id, start_ts, stop_ts, data FROM programmes WHERE rowid > ? ORDER BY rowid LIMIT ?'
        );
        $statement->execute([$afterRowid, $limit]);

        $page = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $page[(int) $row['rowid']] = ['hash' => self::rowHash($row), 'programme' => self::hydrateRow($row)];
        }

        return $page;
    }

    /**
     * Apply programme changes to specific rows, all-or-nothing.
     *
     * Runs in one `BEGIN IMMEDIATE` transaction: every targeted row is re-read
     * and its hash compared with `$expectedHashes` before the first write, so a
     * row changed since the caller read it aborts the whole batch.
     *
     * @param  array<int, string>  $expectedHashes  rowid => hash from {@see readPage()}
     * @param  array<int, array<string, mixed>>  $changes  rowid => fields to overlay on the programme
     * @return array<int, bool>|null rowid => whether that row actually changed, or null on a hash mismatch
     */
    public function updateRows(array $expectedHashes, array $changes): ?array
    {
        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            // A rebuild renames a new store over this path. Writing to the
            // replaced file would be lost, so treat it like a changed row.
            clearstatcache(true, $this->finalPath);
            if ($this->openedInode === false || @fileinode($this->finalPath) !== $this->openedInode) {
                $this->pdo->exec('ROLLBACK');

                return null;
            }

            $rowids = array_keys($expectedHashes);
            $select = $this->pdo->prepare(
                'SELECT rowid, channel_id, start_ts, stop_ts, data FROM programmes WHERE rowid IN ('
                .implode(',', array_fill(0, count($rowids), '?')).')'
            );
            $select->execute($rowids);

            $current = [];
            while (($row = $select->fetch(PDO::FETCH_ASSOC)) !== false) {
                $current[(int) $row['rowid']] = $row;
            }

            foreach ($expectedHashes as $rowid => $hash) {
                if (! isset($current[$rowid]) || ! hash_equals($hash, self::rowHash($current[$rowid]))) {
                    $this->pdo->exec('ROLLBACK');

                    return null;
                }
            }

            $update = $this->pdo->prepare('UPDATE programmes SET data = ? WHERE rowid = ?');
            $changed = [];
            foreach ($changes as $rowid => $fields) {
                $programme = self::hydrateRow($current[$rowid]);
                $patched = array_replace($programme, $fields);
                $changed[$rowid] = $patched !== $programme;
                if ($changed[$rowid]) {
                    $update->execute([
                        json_encode(self::dehydrate($patched), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                        $rowid,
                    ]);
                }
            }

            $this->pdo->exec('COMMIT');

            return $changed;
        } catch (\Throwable $e) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\Throwable) {
                // SQLite already rolled the transaction back.
            }

            throw $e;
        }
    }

    /**
     * Programmes for one date, grouped by channel and ordered by start time.
     * Passing an empty `$channelIds` returns every channel for the date.
     *
     * @param  list<string>  $channelIds
     * @return array<string, list<array<string, mixed>>>
     */
    public function read(string $date, array $channelIds): array
    {
        $channelIds = array_values(array_unique($channelIds));
        $filterInPhp = count($channelIds) > self::MAX_IN_PARAMS;

        $sql = 'SELECT channel_id, start_ts, stop_ts, data FROM programmes WHERE date = ?';
        $params = [$date];
        if ($channelIds !== [] && ! $filterInPhp) {
            $sql .= ' AND channel_id IN ('.implode(',', array_fill(0, count($channelIds), '?')).')';
            array_push($params, ...$channelIds);
        }
        $sql .= ' ORDER BY channel_id, start_ts';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        $wanted = $filterInPhp ? array_flip($channelIds) : null;
        $result = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ($wanted !== null && ! isset($wanted[$row['channel_id']])) {
                continue;
            }
            $result[$row['channel_id']][] = self::hydrateRow($row);
        }

        return $result;
    }

    /**
     * Stream every programme whose local date falls in `[$fromDate, $toDate]`,
     * ordered by date then channel then start, as `[channelId, programmeArray]`
     * pairs. Used to repopulate the `epg_programmes` DB table for DVR.
     *
     * @return Generator<int, array{0: string, 1: array<string, mixed>}>
     */
    public function readForDvr(string $fromDate, string $toDate): Generator
    {
        $statement = $this->pdo->prepare(
            'SELECT channel_id, start_ts, stop_ts, data FROM programmes '
            .'WHERE date >= ? AND date <= ? ORDER BY date, channel_id, start_ts'
        );
        $statement->execute([$fromDate, $toDate]);

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            yield [$row['channel_id'], self::hydrateRow($row)];
        }
    }

    /**
     * @param  array{channel_id: string, start_ts: int|string|null, stop_ts: int|string|null, data: string}  $row
     * @return array<string, mixed>
     */
    private static function hydrateRow(array $row): array
    {
        $decoded = json_decode($row['data'], true);

        return self::hydrate(
            is_array($decoded) ? $decoded : [],
            $row['channel_id'],
            $row['start_ts'] !== null ? (int) $row['start_ts'] : null,
            $row['stop_ts'] !== null ? (int) $row['stop_ts'] : null,
        );
    }
}
