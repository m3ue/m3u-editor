<?php

namespace App\Services\Sports;

use App\Services\EspnSportsService;
use App\Services\TheSportsDbService;
use Carbon\CarbonImmutable;

/**
 * How a game reads on screen: which team comes first, what colours its wash
 * and bars take, and how two stat values compare. Shared by the Sports page's
 * game cards and details.
 */
final class SportsGameDisplay
{
    /**
     * Whether a channel could still show this game.
     *
     * @param  array<string, mixed>  $game
     */
    public static function isWatchable(array $game): bool
    {
        return in_array($game['state'], ['live', 'scheduled'], true);
    }

    /**
     * Whether this game has a score to show.
     *
     * @param  array<string, mixed>  $game
     */
    public static function isScored(array $game): bool
    {
        return in_array($game['state'], ['live', 'finished'], true);
    }

    /**
     * When the game starts, in the app's time zone.
     *
     * @param  array<string, mixed>  $game
     */
    public static function localStart(array $game): CarbonImmutable
    {
        return CarbonImmutable::parse($game['start'])->setTimezone(config('app.timezone'));
    }

    /**
     * A game's two sides in the order its league reads them: `home`/`away`
     * keys, home first where the league lists it first.
     *
     * @param  array<string, mixed>  $game
     * @return list<string>
     */
    public static function sideOrder(array $game): array
    {
        $league = EspnSportsService::findLeague($game['league_id']) ?? TheSportsDbService::findLeague($game['league_id']);

        return $league !== null && EspnSportsService::isHomeFirst($league)
            ? ['home', 'away']
            : ['away', 'home'];
    }

    /**
     * The team's colour as `[r, g, b]`, or null. Black and white (common "no
     * colour" answers) fall back to the alternate, else to none.
     *
     * @param  array<string, mixed>|null  $team
     * @return array{0: int, 1: int, 2: int}|null
     */
    public static function teamColor(?array $team): ?array
    {
        foreach ([$team['color'] ?? null, $team['alternate_color'] ?? null] as $hex) {
            if (! is_string($hex) || preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
                continue;
            }
            $rgb = array_map(hexdec(...), str_split($hex, 2));
            $luminance = self::luminance($rgb);
            if ($luminance >= 0.02 && $luminance <= 0.85) {
                return $rgb;
            }
        }

        return null;
    }

    /**
     * The inline style that washes an element in both teams' colours, or an
     * empty string when neither has one.
     *
     * @param  array<string, mixed>  $game
     */
    public static function washStyle(array $game): string
    {
        $colors = self::sideColors($game);
        if ($colors[0] === null && $colors[1] === null) {
            return '';
        }

        $stops = array_map(
            fn (?array $rgb): string => $rgb === null ? 'transparent' : 'rgb('.implode(' ', $rgb).' / 0.32)',
            $colors,
        );

        return 'background-image: linear-gradient(90deg, '.$stops[0].', '.$stops[1].');';
    }

    /**
     * The colours of a stat bar's two parts, in side order: the teams', unless
     * they'd read as one bar.
     *
     * @param  array<string, mixed>  $game
     * @return array{0: string, 1: string}
     */
    public static function barColors(array $game): array
    {
        [$first, $second] = self::sideColors($game);
        if ($first === null || $second === null || $first === $second) {
            return ['var(--primary-500)', 'var(--danger-500)'];
        }

        return ['rgb('.implode(' ', $first).')', 'rgb('.implode(' ', $second).')'];
    }

    /**
     * Each value's share of the two as a percentage, or null when they can't
     * be compared ("W1", "7-15").
     *
     * @return array{0: float, 1: float}|null
     */
    public static function shares(string $first, string $second): ?array
    {
        $firstValue = str_replace('%', '', trim($first));
        $secondValue = str_replace('%', '', trim($second));
        if (! is_numeric($firstValue) || ! is_numeric($secondValue)) {
            return null;
        }
        $firstValue = (float) $firstValue;
        $secondValue = (float) $secondValue;
        if ($firstValue < 0 || $secondValue < 0 || $firstValue + $secondValue <= 0) {
            return null;
        }
        $firstShare = $firstValue / ($firstValue + $secondValue) * 100;

        return [$firstShare, 100 - $firstShare];
    }

    /**
     * Each team's colour, in side order.
     *
     * @param  array<string, mixed>  $game
     * @return list<array{0: int, 1: int, 2: int}|null>
     */
    private static function sideColors(array $game): array
    {
        return array_map(
            fn (string $side): ?array => self::teamColor($game[$side]['team'] ?? null),
            self::sideOrder($game),
        );
    }

    /**
     * @param  array<int, int>  $rgb
     */
    private static function luminance(array $rgb): float
    {
        [$red, $green, $blue] = array_map(function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    }
}
