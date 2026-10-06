<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Catalogue of holiday / theme presets for the `theme` Dynamic Group source.
 *
 * Theme rules build local-library groups ("Christmas", "Halloween", …) by
 * matching each playlist's own VOD/series rows against a small keyword +
 * text-term list. No TMDB call runs at sync time — `tmdb_keywords` from the
 * existing enrichment pipeline and the item's title/plot text are the only
 * inputs.
 *
 * Each preset optionally declares a recurring seasonal window (`MM-DD` →
 * `MM-DD`). Outside the window the group hides itself (DynamicGroup.enabled
 * flips false, membership clears) but the row itself is kept so the Xtream
 * category id stays stable across the year. `isWithinWindow()` is the single
 * source of truth for window membership, exposed as a pure helper so the
 * SyncDynamicGroups job and the playlist form preview agree.
 *
 * Labels are translated where displayed; this class only owns the keys.
 */
class DynamicGroupThemes
{
    /**
     * Preset catalogue. Keys are stable, lowercase snake_case ids stored on
     * the rule's `tmdb_params.preset`. `keywords` match against stored
     * `tmdb_keywords` (TMDB's own theme tag), `terms` match against the
     * item's title and plot text — together they cover both enriched and
     * non-enriched rows.
     *
     * Terms deliberately avoid bare risky words: `santa` matches "Santa Fe",
     * `easter` matches "Easter Island", so the common spelling of each
     * holiday has to live alongside enough context to be distinctive.
     *
     * `active_from` / `active_until` are `MM-DD`. When `from > until` the
     * window wraps the year end (e.g. `12-26`–`01-02` for New Year's Eve).
     * A missing window means always active.
     *
     * @var array<string, array{label: string, keywords: list<string>, terms: list<string>, active_from: ?string, active_until: ?string}>
     */
    public const PRESETS = [
        'halloween' => [
            'label' => 'Halloween',
            'keywords' => ['halloween'],
            'terms' => ['halloween', 'trick or treat'],
            'active_from' => '10-01',
            'active_until' => '10-31',
        ],
        'christmas' => [
            'label' => 'Christmas',
            'keywords' => ['christmas', 'santa claus', 'christmas eve', 'christmas tree', 'north pole'],
            'terms' => ['christmas', 'xmas', 'santa claus', 'north pole'],
            'active_from' => '11-15',
            'active_until' => '12-26',
        ],
        'thanksgiving' => [
            'label' => 'Thanksgiving',
            'keywords' => ['thanksgiving'],
            'terms' => ['thanksgiving'],
            'active_from' => '11-01',
            'active_until' => '11-30',
        ],
        'valentines' => [
            'label' => "Valentine's Day",
            'keywords' => ["valentine's day"],
            'terms' => ['valentine'],
            'active_from' => '02-01',
            'active_until' => '02-14',
        ],
        'new_years' => [
            'label' => "New Year's Eve",
            'keywords' => ["new year's eve"],
            'terms' => ["new year's eve"],
            'active_from' => '12-26',
            'active_until' => '01-02',
        ],
        'hanukkah' => [
            'label' => 'Hanukkah',
            'keywords' => ['hanukkah'],
            'terms' => ['hanukkah', 'chanukah'],
            'active_from' => '12-01',
            'active_until' => '12-31',
        ],
    ];

    /**
     * Sentinel preset key used by the rule form for "use the rule's own
     * keywords/terms" rather than picking one from PRESETS.
     */
    public const CUSTOM_KEY = 'custom';

    /**
     * Options array suitable for a Filament Select: `[key => translated label]`,
     * with the custom option appended last.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::PRESETS as $key => $preset) {
            $options[$key] = __($preset['label']);
        }
        $options[self::CUSTOM_KEY] = __('Custom');

        return $options;
    }

    /**
     * Look up a preset by key, returning null for unknown keys (including
     * the CUSTOM_KEY sentinel — callers must treat null as "use the rule's
     * own lists").
     *
     * @return array{label: string, keywords: list<string>, terms: list<string>, active_from: ?string, active_until: ?string}|null
     */
    public static function preset(string $key): ?array
    {
        return self::PRESETS[$key] ?? null;
    }

    /**
     * Resolve the keywords/terms a theme rule should match against.
     *
     * - `preset` (string): a key in PRESETS → returns that preset's lists.
     * - anything else (including the CUSTOM_KEY sentinel or null) → returns
     *   the rule's own `tmdb_params.keywords` / `tmdb_params.terms`.
     *
     * Both lists are lowercased and string-cast before returning so callers
     * can pass them straight into a LIKE/JOIN without re-normalizing.
     *
     * @param  array<string, mixed>  $params
     * @return array{keywords: list<string>, terms: list<string>, preset: ?string}
     */
    public static function resolveLists(array $params): array
    {
        $presetKey = (string) ($params['preset'] ?? '');
        $preset = $presetKey !== '' ? self::preset($presetKey) : null;

        if ($preset !== null) {
            return [
                'keywords' => array_map('strval', $preset['keywords']),
                'terms' => array_map('strval', $preset['terms']),
                'preset' => $presetKey,
            ];
        }

        $normalize = static function ($value): array {
            if (! is_array($value)) {
                return [];
            }
            $out = [];
            foreach ($value as $entry) {
                if ($entry === null || $entry === '') {
                    continue;
                }
                $out[] = mb_strtolower(trim((string) $entry));
            }

            return $out;
        };

        return [
            'keywords' => $normalize($params['keywords'] ?? []),
            'terms' => $normalize($params['terms'] ?? []),
            'preset' => null,
        ];
    }

    /**
     * Whether `$now` falls inside the seasonal window.
     *
     * Inclusive on both endpoints. Handles wrap-around (`from > until`,
     * e.g. `12-26`–`01-02`) by checking both halves of the year. A null
     * `from` OR `until` means the window is open — use this when only one
     * side is set, or when a custom rule wants no seasonal cut at all.
     *
     * Pure: no app/now() side effects, no timezone gymnastics beyond what
     * the caller already passed in. SyncDynamicGroups always passes
     * `now()` so the answer matches the app's timezone.
     */
    public static function isWithinWindow(?string $from, ?string $until, CarbonInterface $now): bool
    {
        if ($from === null || $from === '' || $until === null || $until === '') {
            return true;
        }

        $today = self::monthDayValue((int) $now->format('m'), (int) $now->format('d'));
        $fromValue = self::parseMonthDay($from);
        $untilValue = self::parseMonthDay($until);

        if ($fromValue === null || $untilValue === null) {
            return true;
        }

        if ($fromValue <= $untilValue) {
            return $today >= $fromValue && $today <= $untilValue;
        }

        // Wrap-around (e.g. 12-26 → 01-02): inside if today is on or after
        // the start, OR on or before the end.
        return $today >= $fromValue || $today <= $untilValue;
    }

    /**
     * Validate a user-entered `MM-DD` window endpoint. Returns true when the
     * input parses cleanly to a real month and day (so `13-01` and `02-30`
     * are rejected, `02-29` is accepted as February 29). Used as the form
     * validator for `active_from`/`active_until`.
     */
    public static function isValidMonthDay(?string $value): bool
    {
        return self::parseMonthDay($value ?? '') !== null;
    }

    /**
     * Parse `MM-DD` into a comparable `MMDD` integer, or null on garbage.
     * `checkdate` keeps `02-30`, `13-01`, etc. out. We feed it a leap year
     * so Feb 29 is accepted (recurring MM-DD windows can land on a leap
     * day in some years and on Mar 1 in others; both are user-valid).
     */
    private static function parseMonthDay(string $value): ?int
    {
        if ($value === '' || ! preg_match('/^(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }

        $month = (int) $m[1];
        $day = (int) $m[2];
        if (! checkdate($month, $day, 2024)) {
            return null;
        }

        return self::monthDayValue($month, $day);
    }

    private static function monthDayValue(int $month, int $day): int
    {
        return ($month * 100) + $day;
    }
}
