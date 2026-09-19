<?php

namespace App\Services;

use App\Models\Epg;
use App\Plugins\Support\PluginExecutionContext;
use App\Rules\UrlIsAllowed;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Host-owned, conditional enrichment API for an EPG's one canonical cache file.
 *
 * Plugins receive canonical programme data and opaque locators only, then send
 * back patches pinned to the cache revision and row revisions they were given.
 * The host re-checks both inside a single write transaction on the canonical
 * `programmes.sqlite` and commits the row updates together with a new cache
 * revision, so a batch is either published as a whole or not at all.
 *
 * Statuses returned to the plugin:
 * - `ok`, `applied`, `noop` - the request succeeded (or changed nothing).
 * - `conflict`, `stale_snapshot`, `invalid_patch`, `invalid_selection`,
 *   `invalid_cursor`, `invalid_snapshot` - the request is wrong or outdated.
 * - `legacy_cache_read_only` - the cache predates the single-source format.
 * - `transient_error` - the cache was locked by another writer; retry later.
 * - `*_denied` - the plugin or caller is not allowed to touch this EPG.
 *
 * A genuinely failed write (disk error, corrupt store) is not reported as a
 * status at all: it surfaces as an exception so the caller sees a host failure
 * rather than being told its patch was invalid.
 */
class EpgCacheEnrichmentService
{
    private const MAX_PAGE_SIZE = 100;

    private const MAX_PATCHES = 100;

    /** @var list<string> */
    private const MUTABLE_FIELDS = [
        'title', 'subtitle', 'desc', 'category', 'episode_num', 'episode_nums',
        'rating', 'icon', 'images', 'new', 'previously_shown', 'premiere',
        'urls', 'production_year',
    ];

    /** @var list<string> */
    private const BOOLEAN_FIELDS = ['new', 'previously_shown', 'premiere'];

    /** @var list<string> */
    private const STRING_FIELDS = ['title', 'subtitle', 'desc', 'category', 'episode_num', 'rating'];

    /** @var list<string> */
    private const IMAGE_FIELDS = ['url', 'type', 'width', 'height', 'orient', 'size'];

    /** @var list<string> */
    private const IMAGE_TYPES = ['poster', 'banner', 'fanart', 'logo'];

    /** @var list<string> */
    private const IMAGE_ORIENTATIONS = ['P', 'L'];

    public function __construct(private readonly EpgCacheStorage $storage) {}

    /**
     * Read one bounded page of the canonical cache, pinned to its current cache
     * revision. Nothing is copied: the snapshot is a read of the one store.
     *
     * A cache locked by another writer is reported as `transient_error`, not
     * thrown: the read is retryable and says nothing about the plugin's request.
     *
     * @param  array{limit?: int, cursor?: string}  $selection
     * @return array<string, mixed> status is ok, transient_error or one of the denial/selection statuses
     */
    public function snapshot(PluginExecutionContext $context, Epg $epg, array $selection = []): array
    {
        if ($denial = $this->authorizationDenial($context, $epg)) {
            return ['status' => $denial];
        }

        $window = $this->resolvePageWindow($selection);
        if (is_string($window)) {
            return ['status' => $window];
        }

        $directory = $this->storage->resolve($epg);
        if (! $this->storage->isCanonical($epg, $directory)) {
            return $this->snapshotLegacy($context, $epg, $window);
        }

        $store = $this->openReader($epg);
        if ($store === null) {
            return $this->snapshotLegacy($context, $epg, $window);
        }

        try {
            $revision = $store->readCacheRevision();
            $rows = array_map($this->hydrateRawRow(...), $store->readPage($window['after'], $window['limit'] + 1));
        } catch (EpgCacheBusyException) {
            // The read lost a race with another writer: retryable contention,
            // reported the same way apply() reports it rather than as an
            // uncaught throw the plugin would read as a host failure.
            return ['status' => 'transient_error'];
        } finally {
            $store->close();
        }

        if ($revision === null) {
            return $this->snapshotLegacy($context, $epg, $window);
        }

        $hasMore = count($rows) > $window['limit'];
        $rows = array_slice($rows, 0, $window['limit']);

        return [
            'status' => 'ok',
            'cache_revision' => $revision,
            'token' => $this->encryptToken($context, $epg, $revision, $rows),
            'programmes' => array_map($this->publicRow(...), $rows),
            'next_cursor' => $this->nextCursor($rows, $hasMore),
        ];
    }

    /**
     * Conditionally apply an enrichment batch to the canonical cache file.
     *
     * Validation and the revision pre-check run outside the per-EPG mutation
     * lock (they are read-only), then the write transaction re-verifies the
     * same revisions under the lock before committing.
     *
     * @param  list<array{locator: string, row_revision: string, changes: array<string, mixed>}>  $patches
     * @return array{status: string}
     */
    public function apply(PluginExecutionContext $context, Epg $epg, string $token, array $patches): array
    {
        if ($denial = $this->authorizationDenial($context, $epg)) {
            return ['status' => $denial];
        }
        if (count($patches) > self::MAX_PATCHES) {
            return ['status' => 'invalid_patch'];
        }

        $snapshot = $this->decryptToken($token);
        if (! is_array($snapshot) || ($snapshot['epg_id'] ?? null) !== $epg->id || ($snapshot['plugin_id'] ?? null) !== $context->plugin->id) {
            return ['status' => 'invalid_snapshot'];
        }
        if (! is_string($snapshot['cache_revision'] ?? null)) {
            // A snapshot taken from a legacy cache is never mutable.
            return ['status' => 'legacy_cache_read_only'];
        }
        if (! $this->storage->isCanonical($epg, $this->storage->resolve($epg))) {
            return ['status' => 'legacy_cache_read_only'];
        }

        try {
            $prevalidated = $this->prevalidateChanges($epg, $snapshot, $patches);
        } catch (EpgCacheBusyException) {
            // The pre-check read lost a race with another writer: retryable, and
            // nothing to blame on the plugin's batch.
            return ['status' => 'transient_error'];
        }
        if ($prevalidated['status'] !== 'ok') {
            return ['status' => $prevalidated['status']];
        }
        if ($prevalidated['changes'] === []) {
            return ['status' => 'noop'];
        }

        try {
            return $this->storage->mutate($epg, fn (): array => $this->applyWithinMutationLock($context, $epg, $snapshot, $prevalidated['changes']));
        } catch (LockTimeoutException) {
            return ['status' => 'transient_error'];
        } catch (EpgCacheBusyException) {
            return ['status' => 'transient_error'];
        }
    }

    /**
     * Re-check every precondition under the mutation lock and commit the batch
     * in place, so a rebuild that landed while this patch waited cannot be
     * overwritten with data derived from the superseded cache.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, array{programme: array<string, mixed>, expected_revision: string}>  $changes
     * @return array{status: string}
     */
    private function applyWithinMutationLock(PluginExecutionContext $context, Epg $epg, array $snapshot, array $changes): array
    {
        if ($denial = $this->authorizationDenial($context, $epg)) {
            return ['status' => $denial];
        }
        if (! $this->storage->isCanonical($epg, $this->storage->resolve($epg))) {
            return ['status' => 'legacy_cache_read_only'];
        }

        $dataByRowid = [];
        $expectedRevisions = [];
        foreach ($changes as $rowid => $change) {
            $dataByRowid[$rowid] = json_encode(EpgProgrammeStore::dehydrate($change['programme']), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            $expectedRevisions[$rowid] = $change['expected_revision'];
        }

        $store = $this->openWriter($this->programmesPath($epg));
        try {
            $result = $store->applyConditionalUpdates($snapshot['cache_revision'], $dataByRowid, $expectedRevisions);
        } finally {
            $store->close();
        }

        if ($result['status'] !== 'applied') {
            return ['status' => $result['status']];
        }

        $this->clearPlaylistCaches($epg);

        return ['status' => 'applied'];
    }

    /**
     * Open the canonical store for a conditional write.
     *
     * Visibility is protected (not private) so tests can shorten the SQLite
     * busy timeout instead of waiting it out.
     */
    protected function openWriter(string $sqlitePath): EpgProgrammeStore
    {
        $store = new EpgProgrammeStore;
        $store->openWrite($sqlitePath);

        return $store;
    }

    /**
     * Best effort: the enrichment write already published successfully, so a
     * stale playlist cache file must not be reported as a failure.
     */
    private function clearPlaylistCaches(Epg $epg): void
    {
        foreach ($epg->getAllPlaylists() as $playlist) {
            try {
                EpgCacheService::clearPlaylistEpgCacheFile($playlist);
            } catch (Throwable) {
                continue;
            }
        }
    }

    private function authorizationDenial(PluginExecutionContext $context, Epg $epg): ?string
    {
        $plugin = $context->plugin->fresh();
        if (! $plugin || ! $plugin->enabled || ! $plugin->available || ! $plugin->isInstalled()) {
            return 'plugin_not_enabled';
        }
        if (! $plugin->isTrusted() || ! $plugin->hasVerifiedIntegrity() || $plugin->validation_status !== 'valid') {
            return 'plugin_not_trusted';
        }
        if (! in_array('epg_cache_enrichment', $plugin->capabilities ?? [], true)) {
            return 'capability_denied';
        }
        if (! $context->user || (! $context->user->isAdmin() && $context->user->id !== $epg->user_id)) {
            return 'ownership_denied';
        }

        return null;
    }

    /**
     * Validate the requested page, returning the resolved window or the error
     * status to report to the caller.
     *
     * @param  array{limit?: int, cursor?: string}  $selection
     * @return array{limit: int, after: int}|string
     */
    private function resolvePageWindow(array $selection): array|string
    {
        $limit = $selection['limit'] ?? self::MAX_PAGE_SIZE;
        if (! is_int($limit) || $limit < 1 || $limit > self::MAX_PAGE_SIZE) {
            return 'invalid_selection';
        }

        $after = $this->decodeCursor($selection['cursor'] ?? null);
        if ($after === null) {
            return 'invalid_cursor';
        }

        return ['limit' => $limit, 'after' => $after];
    }

    /** @param list<array{rowid: int, programme: array<string, mixed>, revision: string}> $rows */
    private function nextCursor(array $rows, bool $hasMore): ?string
    {
        if (! $hasMore || $rows === []) {
            return null;
        }

        return $this->encodeCursor((int) $rows[array_key_last($rows)]['rowid']);
    }

    /**
     * Open the single canonical programme store for reading, or null when the
     * canonical cache holds no SQLite store yet (legacy JSONL layout).
     *
     * Visibility is protected (not private) so tests can shorten the SQLite
     * busy timeout instead of waiting it out, as {@see openWriter()} does.
     */
    protected function openReader(Epg $epg): ?EpgProgrammeStore
    {
        $path = $this->programmesPath($epg);
        if (! Storage::disk('local')->exists($this->storage->path($epg, EpgCacheStorage::PROGRAMMES_DB_FILE))) {
            return null;
        }

        return EpgProgrammeStore::openRead($path);
    }

    /** Absolute path of the one canonical programme store. */
    private function programmesPath(Epg $epg): string
    {
        return Storage::disk('local')->path($this->storage->path($epg, EpgCacheStorage::PROGRAMMES_DB_FILE));
    }

    /**
     * @param  array{rowid: int, channel_id: string, start_ts: int, stop_ts: ?int, data: string}  $row
     * @return array{rowid: int, programme: array<string, mixed>, revision: string}
     */
    private function hydrateRawRow(array $row): array
    {
        $programme = EpgProgrammeStore::hydrate(json_decode($row['data'], true) ?: [], $row['channel_id'], $row['start_ts'], $row['stop_ts']);

        return ['rowid' => $row['rowid'], 'programme' => $programme, 'revision' => EpgProgrammeStore::revisionOf($programme)];
    }

    /**
     * @param  array{rowid: int, programme: array<string, mixed>, revision: string}  $row
     * @return array<string, mixed>
     */
    private function publicRow(array $row): array
    {
        return [
            'locator' => 'programme:'.base64_encode((string) $row['rowid']),
            'row_revision' => $row['revision'],
            'programme' => $row['programme'],
        ];
    }

    /** @param list<array{rowid: int, programme: array<string, mixed>, revision: string}> $rows */
    private function encryptToken(PluginExecutionContext $context, Epg $epg, ?string $cacheRevision, array $rows): string
    {
        $revisionsByRowid = [];
        foreach ($rows as $row) {
            $revisionsByRowid[(string) $row['rowid']] = $row['revision'];
        }

        return Crypt::encryptString(json_encode([
            'epg_id' => $epg->id,
            'plugin_id' => $context->plugin->id,
            'cache_revision' => $cacheRevision,
            'rows' => $revisionsByRowid,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed>|null */
    private function decryptToken(string $token): ?array
    {
        try {
            $value = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);

            return is_array($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Validate every patch against the snapshot revisions and the canonical
     * cache file, returning only the rows whose programme actually changes.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  list<array<string, mixed>>  $patches
     * @return array{status: string, changes: array<int, array{programme: array<string, mixed>, expected_revision: string}>}
     */
    private function prevalidateChanges(Epg $epg, array $snapshot, array $patches): array
    {
        $expected = $snapshot['rows'] ?? [];
        if (! is_array($expected)) {
            return ['status' => 'conflict', 'changes' => []];
        }

        $pending = [];
        foreach ($patches as $patch) {
            $rowid = is_array($patch) ? $this->decodeLocator($patch['locator'] ?? null) : null;
            if ($rowid === null || isset($pending[$rowid])) {
                return ['status' => 'conflict', 'changes' => []];
            }

            $expectedRevision = $expected[(string) $rowid] ?? null;
            if (! is_string($expectedRevision) || ($patch['row_revision'] ?? null) !== $expectedRevision) {
                return ['status' => 'conflict', 'changes' => []];
            }

            if (! is_array($patch['changes'] ?? null)) {
                return ['status' => 'conflict', 'changes' => []];
            }
            $patchChanges = $this->validatePatch($patch['changes']);
            if ($patchChanges === null) {
                return ['status' => 'conflict', 'changes' => []];
            }

            $pending[$rowid] = ['changes' => $patchChanges, 'expected_revision' => $expectedRevision];
        }

        $store = $this->openReader($epg);
        if ($store === null) {
            return ['status' => 'legacy_cache_read_only', 'changes' => []];
        }

        try {
            $revision = $store->readCacheRevision();
            $current = $store->readRowsByIds(array_keys($pending));
        } finally {
            $store->close();
        }

        if ($revision === null) {
            return ['status' => 'legacy_cache_read_only', 'changes' => []];
        }
        if (! hash_equals($snapshot['cache_revision'], $revision)) {
            return ['status' => 'stale_snapshot', 'changes' => []];
        }

        $changes = [];
        foreach ($pending as $rowid => $patch) {
            $row = $current[$rowid] ?? null;
            if ($row === null || ! hash_equals($patch['expected_revision'], EpgProgrammeStore::rawRowRevision($row))) {
                return ['status' => 'conflict', 'changes' => []];
            }

            $programme = EpgProgrammeStore::hydrate(json_decode($row['data'], true) ?: [], $row['channel_id'], $row['start_ts'], $row['stop_ts']);
            $patched = array_replace($programme, $patch['changes']);
            if ($patched !== $programme) {
                $changes[$rowid] = ['programme' => $patched, 'expected_revision' => $patch['expected_revision']];
            }
        }

        return ['status' => 'ok', 'changes' => $changes];
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>|null
     */
    protected function validatePatch(array $changes): ?array
    {
        if ($changes === [] || array_diff(array_keys($changes), self::MUTABLE_FIELDS) !== []) {
            return null;
        }

        foreach ($changes as $field => $value) {
            $valid = match (true) {
                in_array($field, self::BOOLEAN_FIELDS, true) => is_bool($value),
                in_array($field, self::STRING_FIELDS, true) => is_string($value),
                $field === 'production_year' => is_int($value) || $value === null,
                $field === 'icon' => is_string($value) && ($value === '' || $this->validUrl($value)),
                $field === 'urls' => $this->validUrls($value),
                $field === 'images' => $this->validImages($value),
                $field === 'episode_nums' => is_array($value),
                default => true,
            };

            if (! $valid) {
                return null;
            }
        }

        return $changes;
    }

    private function validUrls(mixed $urls): bool
    {
        if (! is_array($urls)) {
            return false;
        }

        foreach ($urls as $url) {
            if (! is_array($url) || array_diff(array_keys($url), ['system', 'value']) !== []) {
                return false;
            }
            if (! is_string($url['system'] ?? null) || ! $this->validUrl($url['value'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function validImages(mixed $images): bool
    {
        if (! is_array($images)) {
            return false;
        }

        foreach ($images as $image) {
            if (! is_array($image) || array_diff(array_keys($image), self::IMAGE_FIELDS) !== []) {
                return false;
            }
            if (! $this->validUrl($image['url'] ?? null)) {
                return false;
            }
            if (! in_array($image['type'] ?? null, self::IMAGE_TYPES, true) || ! in_array($image['orient'] ?? null, self::IMAGE_ORIENTATIONS, true)) {
                return false;
            }
            if (! is_int($image['width'] ?? null) || ! is_int($image['height'] ?? null) || ! is_int($image['size'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function validUrl(mixed $url): bool
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false && $this->urlAllowed($url);
    }

    /** Reuse the same allowed-domain policy every other URL-accepting surface in the app enforces. */
    private function urlAllowed(string $url): bool
    {
        $denied = false;
        app(UrlIsAllowed::class)->validate('url', $url, function () use (&$denied): void {
            $denied = true;
        });

        return ! $denied;
    }

    private function encodeCursor(int $rowid): string
    {
        return Crypt::encryptString((string) $rowid);
    }

    private function decodeCursor(mixed $cursor): ?int
    {
        if ($cursor === null) {
            return 0;
        }

        try {
            $value = Crypt::decryptString($cursor);

            return ctype_digit($value) ? (int) $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function decodeLocator(mixed $locator): ?int
    {
        if (! is_string($locator) || ! str_starts_with($locator, 'programme:')) {
            return null;
        }

        $value = base64_decode(substr($locator, 10), true);

        return $value !== false && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Read-only snapshot of a pre-single-source cache (flat v2 without revision
     * state, or v1 JSONL). These caches stay readable but are never mutated.
     *
     * @param  array{limit: int, after: int}  $window
     * @return array<string, mixed>
     */
    private function snapshotLegacy(PluginExecutionContext $context, Epg $epg, array $window): array
    {
        $rows = $this->legacyStoreRows($epg, $window['after'], $window['limit'] + 1);
        $hasMore = count($rows) > $window['limit'];
        $rows = array_slice($rows, 0, $window['limit']);

        return [
            'status' => 'ok',
            'cache_revision' => null,
            'token' => $this->encryptToken($context, $epg, null, []),
            'programmes' => array_map($this->publicRow(...), $rows),
            'next_cursor' => $this->nextCursor($rows, $hasMore),
        ];
    }

    /** @return list<array{rowid: int, programme: array<string, mixed>, revision: string}> */
    private function legacyStoreRows(Epg $epg, int $after, int $limit): array
    {
        $store = $this->openReader($epg);
        if ($store === null) {
            return $this->readLegacyJsonlRows($this->storage->resolve($epg), $after, $limit);
        }

        try {
            return array_map($this->hydrateRawRow(...), $store->readPage($after, $limit));
        } finally {
            $store->close();
        }
    }

    /** @return list<array{rowid: int, programme: array<string, mixed>, revision: string}> */
    private function readLegacyJsonlRows(string $directory, int $after, int $limit): array
    {
        $disk = Storage::disk('local');
        $paths = array_values(array_filter($disk->files($directory), fn (string $path): bool => (bool) preg_match('/\/programmes-\d{4}-\d{2}-\d{2}\.jsonl$/', $path)));
        sort($paths, SORT_STRING);

        $rowid = 0;
        $rows = [];
        foreach ($paths as $path) {
            $handle = fopen($disk->path($path), 'r');
            if ($handle === false) {
                continue;
            }

            try {
                while (($line = fgets($handle)) !== false) {
                    $record = json_decode(trim($line), true);
                    if (! is_array($record) || ! is_string($record['channel'] ?? null) || ! is_array($record['programme'] ?? null)) {
                        continue;
                    }

                    $rowid++;
                    if ($rowid <= $after) {
                        continue;
                    }

                    $programme = $record['programme'];
                    $programme['channel'] ??= $record['channel'];
                    $rows[] = ['rowid' => $rowid, 'programme' => $programme, 'revision' => EpgProgrammeStore::revisionOf($programme)];
                    if (count($rows) >= $limit) {
                        return $rows;
                    }
                }
            } finally {
                fclose($handle);
            }
        }

        return $rows;
    }
}
