<?php

namespace App\Support;

/**
 * SeriesKey — derives a stable identifier that groups all recordings of the same
 * "show" within a single DVR setting, regardless of which rule scheduled them.
 *
 * Format: `setting:{id}|title:{normalized_title}`
 *
 * Why scoped per setting: two users can each have their own rule for the same
 * show without colliding. Why title-based: at schedule time we don't yet have
 * a TMDB id (enrichment runs after recording). A future phase may upgrade keys
 * to `setting:{id}|tmdb:{id}` once TMDB lookups are available.
 *
 * Normalization is intentionally aggressive but deterministic so the same human
 * title always produces the same key:
 *   - lowercased
 *   - "&" → "and"
 *   - apostrophes/dashes/punctuation stripped
 *   - leading articles "a "/"an "/"the " removed
 *   - whitespace collapsed
 *   - trailing year suffix "(2019)" / " 2019" removed
 *   - trailing edition tokens (us/uk/au/ca + 4-digit year) stripped
 */
class SeriesKey
{
    /**
     * Build a series_key for a (DVR setting, title) pair.
     */
    public static function for(int $dvrSettingId, ?string $title): ?string
    {
        $normalized = self::normalize($title);

        if ($normalized === '') {
            return null;
        }

        return "setting:{$dvrSettingId}|title:{$normalized}";
    }

    /**
     * Normalize a title to a canonical comparable form.
     *
     * Returns an empty string when the input is null/empty/whitespace-only so
     * callers can detect "no key derivable" via `=== ''`.
     */
    public static function normalize(?string $title): string
    {
        if ($title === null) {
            return '';
        }

        $s = trim($title);

        if ($s === '') {
            return '';
        }

        // Lowercase using mb_ to handle accented chars consistently.
        $s = mb_strtolower($s, 'UTF-8');

        // "&" → "and" before stripping punctuation.
        $s = str_replace('&', ' and ', $s);

        // Strip trailing year-in-parens like "Show (2019)" or "Show [2019]".
        $s = preg_replace('/[\(\[]\s*(19|20)\d{2}\s*[\)\]]\s*$/u', '', $s);

        // Strip trailing region tag + year like "Show US 2019" or "Show UK".
        $s = preg_replace('/\s+(us|uk|au|ca|nz)(\s+(19|20)\d{2})?\s*$/u', '', $s);

        // Strip trailing standalone year "Show 2019".
        $s = preg_replace('/\s+(19|20)\d{2}\s*$/u', '', $s);

        // Drop leading article.
        $s = preg_replace('/^(a|an|the)\s+/u', '', $s);

        // Strip apostrophes/quotes entirely (don't insert spaces — "we're" → "were").
        $s = preg_replace("/['’`\"]/u", '', $s);

        // Replace remaining non-alphanumerics with a space.
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

        // Collapse whitespace.
        $s = preg_replace('/\s+/u', ' ', trim($s));

        return $s;
    }
}
