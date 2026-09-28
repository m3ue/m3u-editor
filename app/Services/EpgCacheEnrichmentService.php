<?php

namespace App\Services;

use App\Models\Epg;
use App\Plugins\Support\PluginExecutionContext;
use App\Rules\UrlIsAllowed;
use PDOException;

/**
 * Lets `epg_cache_enrichment` plugins read an EPG's cached programmes and patch
 * a whitelisted set of fields in place.
 *
 * Purely additive on top of {@see EpgCacheService}: the cache is still built,
 * cleared and read exactly as before. Enrichment only ever updates rows of a
 * finished current-version `programmes.sqlite`, and every write is guarded by a
 * per-row content hash (optimistic concurrency), so it never overwrites a row
 * that changed, and a rebuild simply invalidates outstanding patches. A rebuild
 * discards enrichments, so plugins re-run on the `epg.cache.generated` hook.
 *
 * Statuses: `ok` / `applied` / `noop` on success; `stale` when a row (or the
 * whole store) changed since it was read, re-read and retry; `invalid_request`
 * for a malformed request; `busy` when the store is locked, retry later;
 * `unavailable` when there is no finished store to enrich; `denied`.
 */
class EpgCacheEnrichmentService
{
    public const CAPABILITY = 'epg_cache_enrichment';

    public const MAX_PAGE_SIZE = 500;

    public const MAX_PATCHES = 500;

    private const MAX_STRING_LENGTH = 10000;

    /** SQLite primary result codes for a store locked by another connection. */
    private const SQLITE_BUSY_CODES = [5, 6];

    /** SQLITE_CANTOPEN: the store was removed (rebuild in progress). */
    private const SQLITE_CANTOPEN = 14;

    /** @var list<string> */
    private const STRING_FIELDS = ['title', 'subtitle', 'desc', 'category', 'episode_num', 'rating', 'icon'];

    /** @var list<string> */
    private const BOOLEAN_FIELDS = ['new', 'previously_shown', 'premiere'];

    /** @var list<string> */
    private const IMAGE_KEYS = ['url', 'type', 'width', 'height', 'orient', 'size'];

    /** How long a request waits for another connection's lock before reporting `busy`. */
    protected int $busyTimeoutMs = 5000;

    public function __construct(private readonly EpgCacheService $cacheService) {}

    /**
     * One page of cached programmes. `id` and `hash` of each entry are what a
     * patch sends back; pass `next` as `$afterId` for the following page.
     *
     * @return array{status: string, programmes?: list<array{id: int, hash: string, programme: array<string, mixed>}>, next?: int|null}
     */
    public function snapshot(PluginExecutionContext $context, Epg $epg, int $afterId = 0, int $limit = self::MAX_PAGE_SIZE): array
    {
        if (! $this->isAllowed($context, $epg)) {
            return ['status' => 'denied'];
        }
        if ($afterId < 0 || $limit < 1 || $limit > self::MAX_PAGE_SIZE) {
            return ['status' => 'invalid_request'];
        }

        return $this->withStore($epg, function (EpgProgrammeStore $store) use ($afterId, $limit): array {
            $page = $store->readPage($afterId, $limit + 1);
            $hasMore = count($page) > $limit;
            $page = array_slice($page, 0, $limit, preserve_keys: true);

            $programmes = [];
            foreach ($page as $id => $row) {
                $programmes[] = ['id' => $id, 'hash' => $row['hash'], 'programme' => $row['programme']];
            }

            return [
                'status' => 'ok',
                'programmes' => $programmes,
                'next' => $hasMore ? array_key_last($page) : null,
            ];
        });
    }

    /**
     * Apply patches all-or-nothing. Each patch is
     * `['id' => int, 'hash' => string, 'changes' => [field => value]]` with `id`
     * and `hash` taken from {@see snapshot()}.
     *
     * @param  list<array{id: int, hash: string, changes: array<string, mixed>}>  $patches
     * @return array{status: string}
     */
    public function apply(PluginExecutionContext $context, Epg $epg, array $patches): array
    {
        if (! $this->isAllowed($context, $epg)) {
            return ['status' => 'denied'];
        }
        if ($patches === [] || count($patches) > self::MAX_PATCHES) {
            return ['status' => 'invalid_request'];
        }

        $expectedHashes = [];
        $changes = [];
        foreach ($patches as $patch) {
            $id = $patch['id'] ?? null;
            $hash = $patch['hash'] ?? null;
            $fields = $patch['changes'] ?? null;
            if (! is_int($id) || $id < 1 || isset($expectedHashes[$id]) || ! is_string($hash)
                || ! is_array($fields) || ! $this->isValidChangeSet($fields)) {
                return ['status' => 'invalid_request'];
            }

            $expectedHashes[$id] = $hash;
            $changes[$id] = $fields;
        }

        return $this->withStore($epg, function (EpgProgrammeStore $store) use ($epg, $expectedHashes, $changes): array {
            $changed = $store->updateRows($expectedHashes, $changes);
            if ($changed === null) {
                return ['status' => 'stale'];
            }
            if (! in_array(true, $changed, true)) {
                return ['status' => 'noop'];
            }

            // Served XMLTV files embed programme data; drop them like a cache
            // rebuild does so the next request picks up the enrichment.
            foreach ($epg->getAllPlaylists() as $playlist) {
                EpgCacheService::clearPlaylistEpgCacheFile($playlist);
            }

            return ['status' => 'applied'];
        });
    }

    /**
     * Only an enabled plugin declaring the capability, acting for the EPG's
     * owner (or an admin), may enrich it. Trust and integrity are already
     * enforced before any plugin gets an execution context.
     */
    private function isAllowed(PluginExecutionContext $context, Epg $epg): bool
    {
        $plugin = $context->plugin->fresh();
        if (! $plugin || ! $plugin->enabled || ! in_array(self::CAPABILITY, $plugin->capabilities ?? [], true)) {
            return false;
        }

        return $context->user !== null && ($context->user->isAdmin() || $context->user->id === $epg->user_id);
    }

    /**
     * Run `$callback` against the EPG's finished store, mapping lock contention
     * to `busy` and a store that vanished mid-request to `unavailable`.
     *
     * @param  callable(EpgProgrammeStore): array{status: string}  $callback
     * @return array{status: string}
     */
    private function withStore(Epg $epg, callable $callback): array
    {
        $path = $this->cacheService->getProgrammeStorePath($epg);
        if ($path === null) {
            return ['status' => 'unavailable'];
        }

        $store = null;
        try {
            $store = EpgProgrammeStore::openExisting($path, $this->busyTimeoutMs);

            return $callback($store);
        } catch (PDOException $e) {
            $code = ((int) ($e->errorInfo[1] ?? 0)) & 0xFF;

            return match (true) {
                in_array($code, self::SQLITE_BUSY_CODES, true) => ['status' => 'busy'],
                $code === self::SQLITE_CANTOPEN || ! is_file($path) => ['status' => 'unavailable'],
                default => throw $e,
            };
        } finally {
            $store?->close();
        }
    }

    /** @param array<string, mixed> $fields */
    private function isValidChangeSet(array $fields): bool
    {
        if ($fields === []) {
            return false;
        }

        foreach ($fields as $field => $value) {
            $valid = match (true) {
                in_array($field, self::STRING_FIELDS, true) => is_string($value)
                    && mb_strlen($value) <= self::MAX_STRING_LENGTH
                    && ($field !== 'icon' || $value === '' || $this->isAllowedUrl($value)),
                in_array($field, self::BOOLEAN_FIELDS, true) => is_bool($value),
                $field === 'production_year' => $value === null || is_int($value),
                // Only the canonical shape the XMLTV parser itself stores.
                $field === 'episode_nums' => is_array($value) && EpisodeNumberNormalizer::normalize($value) === $value,
                $field === 'urls' => $this->isListOf($value, fn (mixed $entry): bool => is_array($entry)
                    && is_string($entry['system'] ?? null) && $this->isAllowedUrl($entry['value'] ?? null)),
                $field === 'images' => $this->isListOf($value, fn (mixed $image): bool => is_array($image)
                    && array_diff(array_keys($image), self::IMAGE_KEYS) === []
                    && $this->isAllowedUrl($image['url'] ?? null)),
                default => false,
            };

            if (! $valid) {
                return false;
            }
        }

        return true;
    }

    private function isListOf(mixed $value, callable $isValidEntry): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $entry) {
            if (! $isValidEntry($entry)) {
                return false;
            }
        }

        return true;
    }

    /** Same allowed-domain policy every other URL-accepting surface in the app enforces. */
    private function isAllowedUrl(mixed $url): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $denied = false;
        app(UrlIsAllowed::class)->validate('url', $url, function () use (&$denied): void {
            $denied = true;
        });

        return ! $denied;
    }
}
