<?php

namespace App\Support;

/**
 * EpgProgrammeNormalizer — cleans up XMLTV programme fields from sources that
 * smuggle structured data into free-text fields.
 *
 * Two kinds of provider quirks we handle:
 *
 *   1. Superscript markers in titles (e.g. "Jimmy Kimmel Live!  ᴺᵉʷ"). The
 *      provider uses Unicode-styled glyphs to flag new episodes inside the
 *      title string instead of using the proper <new/> XMLTV element. We
 *      detect and strip the marker, then promote it to a structured boolean.
 *
 *   2. Season/episode numbers prefixed onto descriptions (e.g.
 *      "S24 E110 Goldie Hawn ...\nActual description here."). The provider
 *      omits a structured <episode-num> entry but encodes S/E as plain text
 *      at the start of the description, optionally followed by a per-episode
 *      subtitle on the first line and the long description after a newline.
 *
 * All methods are pure, side-effect free, and operate on copies of input.
 */
class EpgProgrammeNormalizer
{
    /**
     * Detect and strip new-episode superscript markers from a title.
     *
     * Returns the cleaned title plus an `isNew` flag. Callers should OR the
     * flag into any pre-existing `is_new` value derived from the XMLTV
     * <new/> element (do not overwrite a `true` with `false`).
     *
     * @return array{title: string, isNew: bool}
     */
    public static function normalizeTitle(?string $title): array
    {
        $title = trim((string) $title);
        if ($title === '') {
            return ['title' => '', 'isNew' => false];
        }

        $isNew = false;

        // "ᴺᵉʷ" — Unicode superscript "New" used by some XMLTV feeds.
        // U+1D3A MODIFIER LETTER CAPITAL N + U+1D49 MODIFIER LETTER SMALL E
        //   + U+02B7 MODIFIER LETTER SMALL W
        if (str_contains($title, 'ᴺᵉʷ')) {
            $isNew = true;
            $title = str_replace('ᴺᵉʷ', '', $title);
        }

        // Collapse any runs of whitespace introduced by the strip and trim.
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));

        return ['title' => $title, 'isNew' => $isNew];
    }

    /**
     * Extract a leading "S## E###" marker from the description text.
     *
     * Recognised shapes (case-insensitive on S/E, optional space between
     * the season and episode tokens):
     *
     *   "S01 E03 ..."   "S1E3 ..."   "S12E108 Subtitle\nDescription..."
     *
     * Behaviour:
     *
     *   - When the description begins with a recognised marker, season and
     *     episode are extracted as integers (already 1-indexed — onscreen
     *     style, NOT xmltv_ns).
     *   - Remaining text after the marker is split on the first newline:
     *       * before-newline (trimmed) becomes `subtitle` when non-empty
     *       * after-newline (trimmed) becomes the new `description`
     *   - When there is no newline, the remainder is treated as the new
     *     `description` and `subtitle` stays null. (The provider format
     *     varies: with newline → episode subtitle present; without →
     *     generic show synopsis.)
     *   - When no marker is found, returns the original description
     *     unchanged with all other fields null.
     *
     * @return array{season: int|null, episode: int|null, subtitle: string|null, description: string|null}
     */
    public static function extractSeasonEpisodeFromDescription(?string $description): array
    {
        $original = $description;
        $description = (string) $description;

        if ($description === '') {
            return [
                'season' => null,
                'episode' => null,
                'subtitle' => null,
                'description' => $original,
            ];
        }

        // Anchor at start; require S## then optional space then E##, followed by
        // a space (or end-of-string) so we don't catch unrelated leading "S1E"
        // patterns mid-sentence.
        if (! preg_match('/^\s*S(\d{1,4})\s?E(\d{1,6})(?:\s+|$)/i', $description, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'season' => null,
                'episode' => null,
                'subtitle' => null,
                'description' => $original,
            ];
        }

        $season = min(32767, (int) $m[1][0]);
        $episode = min(32767, (int) $m[2][0]);

        // Remainder after the matched prefix.
        $remainder = ltrim(substr($description, $m[0][1] + strlen($m[0][0])));

        $subtitle = null;
        $newDescription = $remainder;
        if (str_contains($remainder, "\n")) {
            [$head, $tail] = explode("\n", $remainder, 2);
            $head = trim($head);
            $tail = trim($tail);
            if ($head !== '') {
                $subtitle = $head;
            }
            $newDescription = $tail !== '' ? $tail : null;
        } else {
            $newDescription = $remainder !== '' ? $remainder : null;
        }

        return [
            'season' => $season,
            'episode' => $episode,
            'subtitle' => $subtitle,
            'description' => $newDescription,
        ];
    }
}
