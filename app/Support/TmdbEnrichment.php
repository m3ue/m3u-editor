<?php

namespace App\Support;

/**
 * Guards TMDB enrichment (written by FetchTmdbIds / AppliesTmdbSelection) against
 * provider metadata refreshes, which replace a VOD channel's `info` / a series'
 * `metadata` wholesale with the Xtream payload.
 */
class TmdbEnrichment
{
    /**
     * Keys only TMDB enrichment writes - Xtream providers never send them, so a
     * refresh would silently drop them and FetchTmdbIds' "already enriched" gate
     * (tmdb_id + plot + cover) would never put them back. Their presence also
     * marks a row as TMDB-enriched.
     */
    public const PROVIDER_ABSENT_KEYS = ['cast_list', 'clearlogo', 'related_tmdb'];

    /**
     * VOD `info` keys TMDB enrichment overwrites with its own value (as opposed to
     * only filling when empty, like plot/cover_big), so on an enriched row the
     * persisted value is TMDB's. Kept over the provider's value when TMDB is preferred.
     */
    public const PREFERRED_VOD_INFO_KEYS = ['backdrop_path', 'cast', 'director', 'youtube_trailer', 'rating', 'vote_count'];

    /** Series `metadata` keys kept over the provider's value when TMDB is preferred. */
    public const PREFERRED_SERIES_METADATA_KEYS = ['vote_count'];

    /**
     * Series columns TMDB enrichment overwrites (rating_5based travels with rating),
     * kept over the provider refresh when TMDB is preferred.
     */
    public const PREFERRED_SERIES_COLUMNS = ['backdrop_path', 'cast', 'director', 'youtube_trailer', 'rating', 'rating_5based'];

    public static function isEnriched(mixed $existing): bool
    {
        $existing = self::toArray($existing);

        foreach (self::PROVIDER_ABSENT_KEYS as $key) {
            if (array_key_exists($key, $existing)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merge freshly fetched provider data over the persisted array: TMDB-only keys
     * are always carried over when the provider didn't send them, and - when
     * $preferredKeys is given and the row is TMDB-enriched - those keys keep their
     * persisted (TMDB) value over the provider's.
     *
     * @param  list<string>  $preferredKeys
     */
    public static function preserveOnProviderRefresh(mixed $existing, ?array $incoming, array $preferredKeys = []): ?array
    {
        $existing = self::toArray($existing);

        if ($incoming === null || $existing === []) {
            return $incoming;
        }

        foreach (self::PROVIDER_ABSENT_KEYS as $key) {
            if (array_key_exists($key, $existing) && ! array_key_exists($key, $incoming)) {
                $incoming[$key] = $existing[$key];
            }
        }

        if ($preferredKeys !== [] && self::isEnriched($existing)) {
            foreach ($preferredKeys as $key) {
                if (! blank($existing[$key] ?? null)) {
                    $incoming[$key] = $existing[$key];
                }
            }
        }

        return $incoming;
    }

    /**
     * Persisted info/metadata is normally cast to an array, but legacy rows can
     * hold a JSON-encoded string instead.
     *
     * @return array<string, mixed>
     */
    private static function toArray(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }
}
