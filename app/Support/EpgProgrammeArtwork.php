<?php

namespace App\Support;

/**
 * Picks role-specific artwork out of a cached programme's `images` list.
 *
 * Source `type` labels are not trusted on their own: Schedules Direct maps
 * its "Iconic" category (often 16:9) to `poster`, and older caches labelled
 * every untyped XMLTV <icon> a portrait poster. Known pixel dimensions
 * decide orientation; a declared `orient` is only used when there are none.
 */
class EpgProgrammeArtwork
{
    /** Image roles allowed by the XMLTV DTD's <image type> attribute. */
    public const ROLES = ['poster', 'backdrop', 'still', 'person', 'character'];

    /**
     * The portrait poster: an image typed `poster` (or untyped) whose
     * orientation is portrait, else a typed `poster` with no orientation
     * at all (XMLTV makes <image orient> optional). Known-portrait images
     * rank first, then the largest.
     *
     * @return array<string, mixed>|null
     */
    public static function poster(mixed $images): ?array
    {
        $posters = array_values(array_filter(
            self::validImages($images),
            fn (array $image): bool => match (self::orientation($image)) {
                'P' => in_array(self::type($image), ['poster', ''], true),
                null => self::type($image) === 'poster',
                default => false,
            },
        ));

        usort($posters, fn (array $a, array $b): int => [self::orientation($b) === 'P', self::area($b)]
            <=> [self::orientation($a) === 'P', self::area($a)]);

        return $posters[0] ?? null;
    }

    /**
     * The guide frames programme art at 16:9, so when the source carries
     * alternative images (Schedules Direct sends several aspect ratios, and
     * the cache keeps every <icon> after the first under `images`), pick the
     * landscape one closest to 16:9, largest first on ties. Null when there
     * is none.
     *
     * @return array<string, mixed>|null
     */
    public static function wide(mixed $images): ?array
    {
        $best = null;
        $bestDistance = null;
        $bestWidth = 0;
        foreach (self::validImages($images) as $image) {
            $width = (int) ($image['width'] ?? 0);
            $height = (int) ($image['height'] ?? 0);
            if ($height <= 0 || $width <= $height) {
                continue;
            }

            $distance = round(abs($width / $height - 16 / 9), 2);
            if ($bestDistance === null || $distance < $bestDistance || ($distance === $bestDistance && $width > $bestWidth)) {
                $best = $image;
                $bestDistance = $distance;
                $bestWidth = $width;
            }
        }

        return $best;
    }

    /**
     * The landscape backdrop: the programme (not cast) image closest to
     * 16:9, else a declared `backdrop` without dimensions that isn't marked
     * portrait.
     *
     * @return array<string, mixed>|null
     */
    public static function backdrop(mixed $images): ?array
    {
        $programmeImages = array_values(array_filter(
            self::validImages($images),
            fn (array $image): bool => ! in_array(self::type($image), ['person', 'character'], true),
        ));

        return self::wide($programmeImages) ?? collect($programmeImages)->first(
            fn (array $image): bool => self::type($image) === 'backdrop' && self::orientation($image) !== 'P',
        );
    }

    /**
     * `P` or `L` from known dimensions, else the declared `orient`, else null.
     *
     * @param  array<string, mixed>  $image
     */
    public static function orientation(array $image): ?string
    {
        $width = (int) ($image['width'] ?? 0);
        $height = (int) ($image['height'] ?? 0);
        if ($width > 0 && $height > 0) {
            return match (true) {
                $height > $width => 'P',
                $width > $height => 'L',
                default => null,
            };
        }

        $declared = strtoupper(trim((string) ($image['orient'] ?? '')));

        return in_array($declared, ['P', 'L'], true) ? $declared : null;
    }

    /**
     * XMLTV <image size>: 1 small (<200px), 2 medium (<=400px), 3 large.
     * Derived from known dimensions, else the declared value when valid.
     *
     * @param  array<string, mixed>  $image
     */
    public static function size(array $image): ?int
    {
        $width = (int) ($image['width'] ?? 0);
        $height = (int) ($image['height'] ?? 0);
        if ($width > 0 && $height > 0) {
            $largest = max($width, $height);

            return match (true) {
                $largest < 200 => 1,
                $largest <= 400 => 2,
                default => 3,
            };
        }

        $declared = (int) ($image['size'] ?? 0);

        return in_array($declared, [1, 2, 3], true) ? $declared : null;
    }

    /** @param  array<string, mixed>  $image */
    public static function type(array $image): string
    {
        return strtolower(trim((string) ($image['type'] ?? '')));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function validImages(mixed $images): array
    {
        if (! is_array($images)) {
            return [];
        }

        return array_values(array_filter(
            $images,
            fn (mixed $image): bool => is_array($image)
                && filter_var(trim((string) ($image['url'] ?? '')), FILTER_VALIDATE_URL) !== false,
        ));
    }

    /** @param  array<string, mixed>  $image */
    private static function area(array $image): int
    {
        return max(0, (int) ($image['width'] ?? 0)) * max(0, (int) ($image['height'] ?? 0));
    }
}
