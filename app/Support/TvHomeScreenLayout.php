<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The shape of an M3U TV Home screen, shared by the editor form and the
 * `client_config.home_screen` payload.
 *
 * Sections are the app's customizable nav destinations (Home, Search,
 * Continue Watching, Notifications and Settings stay pinned in the app and
 * never appear here). Every section keeps its built-in `default` row; some
 * also take custom rows of one kind. Keys are never renamed or removed: an
 * app ignores sections and row kinds it doesn't know.
 */
class TvHomeScreenLayout
{
    /** First M3U TV release that reads `client_config.home_screen`. */
    public const MIN_APP_VERSION = '1.3.0';

    public const ROW_DEFAULT = 'default';

    public const ROW_CATEGORY = 'category';

    public const ROW_SPORTS_FIXTURES = 'sports_fixtures';

    /** Row kinds an app that doesn't say otherwise can draw. */
    public const BASELINE_ROW_KINDS = [self::ROW_DEFAULT];

    public const SPORTS_RANGES = ['today', 'next_3_days'];

    public const SPORTS_DEFAULT_LIMIT = 12;

    public const SPORTS_MAX_LIMIT = 30;

    private const MONTH_DAY_PATTERN = '/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/';

    /**
     * In the app's default order, with the custom row kind each takes and,
     * for category rows, the Xtream category type.
     *
     * @var array<string, array{custom_kind: string|null, category_type?: string, home: bool}>
     */
    public const SECTIONS = [
        'live_tv' => ['custom_kind' => self::ROW_CATEGORY, 'category_type' => 'live', 'home' => true],
        'sports' => ['custom_kind' => self::ROW_SPORTS_FIXTURES, 'home' => true],
        'movies' => ['custom_kind' => self::ROW_CATEGORY, 'category_type' => 'vod', 'home' => true],
        'series' => ['custom_kind' => self::ROW_CATEGORY, 'category_type' => 'series', 'home' => true],
        'aiostreams' => ['custom_kind' => null, 'home' => false],
        'dvr' => ['custom_kind' => null, 'home' => true],
        'requests' => ['custom_kind' => null, 'home' => false],
    ];

    /**
     * @return array<string, string>
     */
    public static function sectionLabels(): array
    {
        return [
            'live_tv' => __('Live TV'),
            'sports' => __('Sports'),
            'movies' => __('Movies'),
            'series' => __('Series'),
            'aiostreams' => __('AIOStreams'),
            'dvr' => __('DVR'),
            'requests' => __('Requests'),
        ];
    }

    public static function customKindFor(?string $section): ?string
    {
        return self::SECTIONS[$section]['custom_kind'] ?? null;
    }

    public static function categoryTypeFor(?string $section): ?string
    {
        return self::SECTIONS[$section]['category_type'] ?? null;
    }

    public static function isMonthDay(mixed $value): bool
    {
        return is_string($value) && preg_match(self::MONTH_DAY_PATTERN, $value) === 1;
    }

    /**
     * MM-DD from either MM-DD or a full date (the editor's date picker sends
     * YYYY-MM-DD; only the month and day count).
     */
    public static function toMonthDay(mixed $value): ?string
    {
        if (is_string($value) && preg_match('/^\d{4}-(\d{2}-\d{2})(?:[ T].*)?$/', $value, $matches) === 1) {
            $value = $matches[1];
        }

        return self::isMonthDay($value) ? $value : null;
    }

    /**
     * The app's own layout: every section in default order, each with only
     * its built-in row.
     *
     * @return list<array<string, mixed>>
     */
    public static function defaultSections(): array
    {
        return array_map(
            fn (string $key): array => self::defaultSection($key),
            array_keys(self::SECTIONS),
        );
    }

    /**
     * Keeps every known section exactly once (missing ones join in default
     * order), gives each exactly one built-in row, and drops rows that are
     * incomplete or of a kind the section doesn't take.
     *
     * @param  array<int, mixed>|null  $sections
     * @return list<array<string, mixed>>
     */
    public static function normalize(?array $sections): array
    {
        $normalized = [];

        foreach ($sections ?? [] as $section) {
            $key = is_array($section) ? ($section['section'] ?? null) : null;

            if (! is_string($key) || ! isset(self::SECTIONS[$key]) || isset($normalized[$key])) {
                continue;
            }

            $normalized[$key] = self::normalizeSection($key, $section);
        }

        foreach (array_keys(self::SECTIONS) as $key) {
            $normalized[$key] ??= self::defaultSection($key);
        }

        return array_values($normalized);
    }

    /**
     * The layout as sent to an app that can draw [$rowKinds].
     *
     * @param  list<array<string, mixed>>  $sections
     * @param  list<string>  $rowKinds
     * @return list<array<string, mixed>>
     */
    public static function forRowKinds(array $sections, array $rowKinds): array
    {
        return array_map(function (array $section) use ($rowKinds): array {
            $section['rows'] = array_values(array_filter(
                $section['rows'],
                fn (array $row): bool => in_array($row['kind'], $rowKinds, true),
            ));

            return $section;
        }, $sections);
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaultSection(string $key): array
    {
        return [
            'section' => $key,
            'nav' => true,
            'home' => self::SECTIONS[$key]['home'],
            'rows' => [self::defaultRow()],
        ];
    }

    /**
     * @return array{id: string, kind: string}
     */
    private static function defaultRow(): array
    {
        return ['id' => self::ROW_DEFAULT, 'kind' => self::ROW_DEFAULT];
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private static function normalizeSection(string $key, array $section): array
    {
        $rows = [];
        $hasDefault = false;

        foreach ((array) ($section['rows'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (($row['kind'] ?? null) === self::ROW_DEFAULT) {
                if (! $hasDefault) {
                    $rows[] = self::defaultRow();
                    $hasDefault = true;
                }

                continue;
            }

            $normalizedRow = self::normalizeCustomRow($key, $row);

            if ($normalizedRow !== null) {
                $rows[] = $normalizedRow;
            }
        }

        if (! $hasDefault) {
            array_unshift($rows, self::defaultRow());
        }

        return [
            'section' => $key,
            'nav' => self::toBool($section['nav'] ?? true),
            'home' => self::toBool($section['home'] ?? self::SECTIONS[$key]['home']),
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private static function normalizeCustomRow(string $section, array $row): ?array
    {
        $kind = $row['kind'] ?? null;
        $title = trim((string) ($row['title'] ?? ''));

        if ($kind === null || $kind !== self::customKindFor($section) || $title === '') {
            return null;
        }

        $params = (array) ($row['params'] ?? []);

        $params = match ($kind) {
            self::ROW_CATEGORY => filled($params['category_id'] ?? null)
                ? ['category_id' => (string) $params['category_id']]
                : null,
            self::ROW_SPORTS_FIXTURES => self::normalizeSportsParams($params),
            default => null,
        };

        if ($params === null) {
            return null;
        }

        $id = $row['id'] ?? null;

        $normalized = [
            'id' => is_string($id) && $id !== '' && $id !== self::ROW_DEFAULT ? $id : (string) Str::uuid(),
            'kind' => $kind,
            'title' => Str::limit($title, 100, ''),
            'params' => $params,
        ];

        $window = self::normalizeWindow($row['window'] ?? null);

        if ($window !== null) {
            $normalized['window'] = $window;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{leagues: list<string>, range: string, limit: int}|null
     */
    private static function normalizeSportsParams(array $params): ?array
    {
        $leagues = array_values(array_unique(array_filter(
            (array) ($params['leagues'] ?? []),
            fn (mixed $league): bool => is_string($league) && $league !== '',
        )));

        if ($leagues === []) {
            return null;
        }

        $range = in_array($params['range'] ?? null, self::SPORTS_RANGES, true)
            ? $params['range']
            : self::SPORTS_RANGES[0];

        $limit = is_numeric($params['limit'] ?? null) ? (int) $params['limit'] : self::SPORTS_DEFAULT_LIMIT;

        return [
            'leagues' => $leagues,
            'range' => $range,
            'limit' => max(1, min(self::SPORTS_MAX_LIMIT, $limit)),
        ];
    }

    /**
     * A yearly MM-DD window, kept only when both ends are valid. It may wrap
     * the new year (12-15 to 01-05).
     *
     * @return array{from: string, to: string}|null
     */
    private static function normalizeWindow(mixed $window): ?array
    {
        if (! is_array($window)) {
            return null;
        }

        $from = self::toMonthDay($window['from'] ?? null);
        $to = self::toMonthDay($window['to'] ?? null);

        if ($from === null || $to === null) {
            return null;
        }

        return ['from' => $from, 'to' => $to];
    }

    private static function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
