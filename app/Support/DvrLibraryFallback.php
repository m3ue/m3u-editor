<?php

namespace App\Support;

/**
 * Tracks which library fields of a DVR recording hold stand-in values (generated
 * artwork, the channel logo as title logo, the EPG description or "Recorded ..."
 * synopsis, the EPG category as genre) rather than provider metadata.
 *
 * DvrVodIntegrationService lists those fields under {@see KEY} in the item's own
 * metadata bag (a movie's `info`, a series' `metadata`, an episode's `info`).
 * TMDB enrichment fills fields "only when empty", so it treats a listed field as
 * empty too: a later match replaces the stand-in, and the field leaves the list.
 */
final class DvrLibraryFallback
{
    public const KEY = 'dvr_fallback';

    /**
     * [$bag] with [$fields] recorded as holding fallback values (only those that
     * actually hold a value), replacing any earlier list.
     *
     * @param  array<string, mixed>  $bag
     * @param  list<string>  $fields
     * @param  array<string, mixed>  $values  where the values live when not in [$bag] (model columns)
     * @return array<string, mixed>
     */
    public static function mark(array $bag, array $fields, array $values = []): array
    {
        $marked = array_values(array_filter($fields, fn (string $field): bool => filled($values[$field] ?? $bag[$field] ?? null)));
        unset($bag[self::KEY]);

        return $marked === [] ? $bag : [...$bag, self::KEY => $marked];
    }

    /**
     * Whether [$field] is empty or holds a fallback value, so provider metadata may
     * fill it. [$value] is the field's value when it is a model column rather than
     * a key of [$bag].
     *
     * @param  array<string, mixed>|null  $bag
     */
    public static function replaceable(?array $bag, string $field, mixed $value = null): bool
    {
        return blank(func_num_args() > 2 ? $value : ($bag[$field] ?? null))
            || self::isFallback($bag, $field);
    }

    /**
     * @param  array<string, mixed>|null  $bag
     */
    public static function isFallback(?array $bag, string $field): bool
    {
        return in_array($field, $bag[self::KEY] ?? [], true);
    }

    /**
     * [$bag] with [$fields] no longer recorded as fallbacks (they now hold provider
     * metadata). Drops the list once it is empty.
     *
     * @param  array<string, mixed>  $bag
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public static function release(array $bag, array $fields): array
    {
        if (! isset($bag[self::KEY])) {
            return $bag;
        }

        $remaining = array_values(array_diff($bag[self::KEY], $fields));
        unset($bag[self::KEY]);

        return $remaining === [] ? $bag : [...$bag, self::KEY => $remaining];
    }
}
