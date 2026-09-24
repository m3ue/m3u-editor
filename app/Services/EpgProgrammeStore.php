<?php

namespace App\Services;

use Generator;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

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
 *
 * The store also carries its own `cache_state.cache_revision`, which
 * {@see applyConditionalUpdates()} bumps in the same commit as an in-place
 * enrichment batch - so a batch is either visible as a whole or not at all, and
 * a reader holding an older revision can be told its snapshot is stale.
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

    /** How long a conditional update waits for SQLite's write lock before failing as busy. */
    private const DEFAULT_BUSY_TIMEOUT_MS = 10000;

    /** Key of the published cache revision inside {@see CACHE_STATE_TABLE}. */
    private const REVISION_KEY = 'revision';

    private const CACHE_STATE_TABLE = 'cache_state';

    private ?PDO $pdo = null;

    private ?PDOStatement $insertStatement = null;

    private int $pendingRows = 0;

    private string $path = '';

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

    /**
     * Revision of one programme. The enrichment API pins this per row, so the
     * definition has to live with the stored format rather than in a caller.
     *
     * @param  array<string, mixed>  $programme
     */
    public static function revisionOf(array $programme): string
    {
        return hash('sha256', json_encode($programme, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * Revision of one raw `rowid, channel_id, start_ts, stop_ts, data` row,
     * matching {@see revisionOf()} on the hydrated programme.
     *
     * @param  array{rowid: int, channel_id: string, start_ts: int, stop_ts: ?int, data: string}  $row
     */
    public static function rawRowRevision(array $row): string
    {
        return self::revisionOf(self::hydrateRow($row));
    }

    private static function timestampToIso(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s', $timestamp).'.000000Z';
    }

    /**
     * Open a fresh writer that builds a complete store at `$sqlitePath`.
     *
     * A rebuild passes a staging path and only publishes it once
     * {@see finish()} succeeded, so a half-written store is never visible under
     * a canonical file name.
     */
    public function beginWrite(string $sqlitePath): void
    {
        $this->path = $sqlitePath;

        $directory = dirname($sqlitePath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        if (is_file($sqlitePath)) {
            @unlink($sqlitePath);
        }

        $this->pdo = new PDO('sqlite:'.$sqlitePath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // The store is disposable and rebuilt from XML, and this run is the sole
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
        $this->pdo->exec('CREATE TABLE '.self::CACHE_STATE_TABLE.' (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $this->pdo->prepare('INSERT INTO '.self::CACHE_STATE_TABLE.' (key, value) VALUES (?, ?)')
            ->execute([self::REVISION_KEY, self::freshRevision()]);

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
     * Commit the rows, build the lookup index and close the store.
     *
     * Publication is deliberately not part of this: a staging file built by a
     * rebuild becomes the canonical store only when
     * {@see EpgCacheStorage::publishRebuild()} renames it into place.
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
        $this->close();
    }

    /**
     * Abandon a half-written store: close it and delete the file this writer
     * created, leaving any other file (the canonical store of an unfinished
     * rebuild's EPG) untouched.
     */
    public function discard(): void
    {
        $this->close();

        if ($this->path !== '' && is_file($this->path)) {
            @unlink($this->path);
        }
    }

    /**
     * Open an existing store for reading. Caller is responsible for checking the
     * file exists first and for {@see close()}ing when done.
     *
     * `$busyTimeoutMs` overrides SQLite's default (60s) busy timeout. Exposure
     * is for tests, so a contended read can be exercised without waiting the
     * default out; leaving it null keeps the driver default.
     */
    public static function openRead(string $sqlitePath, ?int $busyTimeoutMs = null): self
    {
        $store = new self;
        $store->path = $sqlitePath;
        $store->pdo = new PDO('sqlite:'.$sqlitePath);
        $store->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($busyTimeoutMs !== null) {
            // Explicit int cast: the timeout is concatenated into a PRAGMA
            // statement, which SQLite cannot parameterize.
            $store->pdo->exec('PRAGMA busy_timeout='.(int) $busyTimeoutMs);
        }

        return $store;
    }

    /**
     * Open this store for conditional in-place updates of the canonical file.
     *
     * `$busyTimeoutMs` bounds how long SQLite waits for the write lock, so a
     * cache that is busy right now surfaces as a retryable
     * {@see EpgCacheBusyException} instead of stalling the caller.
     */
    public function openWrite(string $sqlitePath, int $busyTimeoutMs = self::DEFAULT_BUSY_TIMEOUT_MS): void
    {
        $this->path = $sqlitePath;
        $this->pdo = new PDO('sqlite:'.$sqlitePath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Rollback journal, not WAL: one canonical file, no -wal/-shm sidecars.
        $this->pdo->exec('PRAGMA journal_mode=DELETE');
        // Explicit int cast: the timeout is concatenated into a PRAGMA
        // statement, which SQLite cannot parameterize.
        $this->pdo->exec('PRAGMA busy_timeout='.(int) $busyTimeoutMs);
    }

    public function close(): void
    {
        $this->insertStatement = null;
        $this->pdo = null;
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
     * Page through raw rows by rowid, independent of the date/channel index.
     * Used by the plugin enrichment API, which addresses programmes by opaque
     * rowid locator rather than by date/channel.
     *
     * A read that failed because another writer holds SQLite's lock is not an
     * empty page: it surfaces as {@see EpgCacheBusyException} so the caller can
     * report retryable contention - the same distinction
     * {@see readRowsByIds()} and {@see readCacheRevision()} make.
     *
     * @return list<array{rowid: int, channel_id: string, start_ts: int, stop_ts: ?int, data: string}>
     *
     * @throws EpgCacheBusyException when another connection holds the lock
     */
    public function readPage(int $afterRowid, int $limit): array
    {
        try {
            $statement = $this->pdo->prepare('SELECT rowid, channel_id, start_ts, stop_ts, data FROM programmes WHERE rowid > ? ORDER BY rowid LIMIT ?');
            $statement->execute([$afterRowid, $limit]);

            return array_values($this->fetchRawRows($statement));
        } catch (PDOException $e) {
            throw EpgCacheBusyException::forSqlite($e) ?? $e;
        }
    }

    /**
     * Fetch specific rows by rowid in a single query, so a caller re-verifying
     * a batch of patch locators does not open one connection/query per row.
     *
     * A read that failed because another writer holds SQLite's lock is not a
     * missing row: it surfaces as {@see EpgCacheBusyException} so the caller can
     * report retryable contention - the same distinction
     * {@see readCacheRevision()} makes for the revision pre-check.
     *
     * @param  list<int>  $rowids
     * @return array<int, array{rowid: int, channel_id: string, start_ts: int, stop_ts: ?int, data: string}> keyed by rowid
     *
     * @throws EpgCacheBusyException when another connection holds the lock
     */
    public function readRowsByIds(array $rowids): array
    {
        if ($rowids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($rowids), '?'));

        try {
            $statement = $this->pdo->prepare("SELECT rowid, channel_id, start_ts, stop_ts, data FROM programmes WHERE rowid IN ({$placeholders})");
            $statement->execute(array_values($rowids));

            return $this->fetchRawRows($statement);
        } catch (PDOException $e) {
            throw EpgCacheBusyException::forSqlite($e) ?? $e;
        }
    }

    /**
     * The cache revision this store publishes, or null for a store written
     * before revisions existed - a legacy cache that stays read-only.
     *
     * A read that failed because another writer holds SQLite's lock is not a
     * cache format: it surfaces as {@see EpgCacheBusyException} so the caller
     * can report retryable contention instead of a legacy read-only cache.
     */
    public function readCacheRevision(): ?string
    {
        try {
            $statement = $this->pdo->prepare('SELECT value FROM '.self::CACHE_STATE_TABLE.' WHERE key = ?');
            $statement->execute([self::REVISION_KEY]);
            $value = $statement->fetchColumn();
        } catch (PDOException $e) {
            if ($busy = EpgCacheBusyException::forSqlite($e)) {
                throw $busy;
            }

            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Apply a prevalidated enrichment batch in place, all-or-nothing.
     *
     * One `BEGIN IMMEDIATE` transaction on the single canonical store: the
     * cache revision and every targeted row revision are re-read and must match
     * the caller's expectations before the first UPDATE, and the new cache
     * revision is committed together with the row updates. Readers therefore
     * observe either the state before or after the batch, never a partially
     * applied one, and an UPDATE that fails aborts the whole batch instead of
     * silently no-op'ing.
     *
     * @param  string  $expectedCacheRevision  revision the caller validated against
     * @param  array<int, string>  $dataByRowid  new JSON blob keyed by rowid
     * @param  array<int, string>  $expectedRevisions  row revision keyed by rowid
     * @return array{status: string, revision: string|null} status is applied, noop, conflict, stale_snapshot or legacy_cache_read_only
     *
     * @throws EpgCacheBusyException when another connection holds the write lock
     */
    public function applyConditionalUpdates(string $expectedCacheRevision, array $dataByRowid, array $expectedRevisions): array
    {
        try {
            $this->pdo->exec('BEGIN IMMEDIATE');

            $revision = $this->readCacheRevision();
            if ($revision === null) {
                return $this->failedConditionalUpdate('legacy_cache_read_only', null);
            }
            if (! hash_equals($revision, $expectedCacheRevision)) {
                return $this->failedConditionalUpdate('stale_snapshot', $revision);
            }

            $current = $this->readRowsByIds(array_keys($dataByRowid));
            foreach ($expectedRevisions as $rowid => $expected) {
                $row = $current[(int) $rowid] ?? null;
                if ($row === null || ! hash_equals($expected, self::rawRowRevision($row))) {
                    return $this->failedConditionalUpdate('conflict', $revision);
                }
            }

            $this->beforeCommit();

            $statement = $this->pdo->prepare('UPDATE programmes SET data = ? WHERE rowid = ?');
            foreach ($dataByRowid as $rowid => $data) {
                $statement->execute([$data, (int) $rowid]);
            }

            $next = self::freshRevision();
            $this->pdo->prepare('UPDATE '.self::CACHE_STATE_TABLE.' SET value = ? WHERE key = ?')->execute([$next, self::REVISION_KEY]);
            $this->pdo->commit();

            return ['status' => 'applied', 'revision' => $next];
        } catch (PDOException $e) {
            $this->rollBack();

            throw EpgCacheBusyException::forSqlite($e) ?? $e;
        } catch (Throwable $e) {
            $this->rollBack();

            throw $e;
        }
    }

    /**
     * Run SQLite's fast structural check. Cheaper than `PRAGMA integrity_check`
     * and enough to catch a truncated or partially-written database file.
     */
    public function quickCheck(): bool
    {
        return $this->pdo->query('PRAGMA quick_check')->fetchColumn() === 'ok';
    }

    /**
     * Called inside the conditional-update transaction, after every revision has
     * been re-verified and before the revision bump + COMMIT. Exists so a test
     * can observe what a concurrent reader sees while a batch is still
     * unpublished.
     */
    protected function beforeCommit(): void {}

    /** @param array{status: string, revision: string|null} $result */
    private function failedConditionalUpdate(string $status, ?string $revision): array
    {
        $this->rollBack();

        return ['status' => $status, 'revision' => $revision];
    }

    /** Release the write transaction, tolerating a transaction SQLite already rolled back. */
    private function rollBack(): void
    {
        try {
            if ($this->pdo?->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (Throwable) {
            // Nothing left to release.
        }
    }

    private static function freshRevision(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Drain a `rowid, channel_id, start_ts, stop_ts, data` result set into
     * normalised raw rows keyed by rowid.
     *
     * @return array<int, array{rowid: int, channel_id: string, start_ts: int, stop_ts: ?int, data: string}>
     */
    private function fetchRawRows(PDOStatement $statement): array
    {
        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $raw = self::rawRow($row);
            $rows[$raw['rowid']] = $raw;
        }

        return $rows;
    }

    /**
     * @param  array{rowid: int|string, channel_id: string, start_ts: int|string, stop_ts: int|string|null, data: string}  $row
     * @return array{rowid: int, channel_id: string, start_ts: int, stop_ts: ?int, data: string}
     */
    private static function rawRow(array $row): array
    {
        return [
            'rowid' => (int) $row['rowid'],
            'channel_id' => $row['channel_id'],
            'start_ts' => (int) $row['start_ts'],
            'stop_ts' => $row['stop_ts'] !== null ? (int) $row['stop_ts'] : null,
            'data' => $row['data'],
        ];
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
