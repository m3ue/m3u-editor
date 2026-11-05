<?php

namespace App\Services\Sports;

/**
 * Maps ESPN's `/summary` answer for one game to the sections every provider's
 * game details share. The answer differs a lot by sport and by how far along
 * the game is, so every section may come back empty.
 */
final class EspnFixtureDetails
{
    use MapsSportsData;

    /** Above this many, a sport's scoring plays are a feed, not highlights (basketball). */
    private const int MAX_SCORING_PLAYS = 60;

    private const int MAX_LEADER_CATEGORIES = 6;

    /**
     * Baseball's box score holds about 170 season-style numbers in groups.
     * These are the ones a score line would show, by group.
     */
    private const array GROUPED_STATS = [
        'batting' => ['hits', 'homeRuns', 'RBIs', 'walks', 'strikeouts', 'stolenBases', 'runnersLeftOnBase', 'avg'],
        'pitching' => ['ERA', 'pitchCount'],
        'fielding' => ['errors'],
    ];

    /**
     * @param  array<string, mixed>  $json
     * @return array{line_score: array<string, list<string>>|null, events: list<array<string, mixed>>, stats: list<array<string, string>>, leaders: list<array<string, mixed>>, info: array<string, mixed>}
     */
    public static function parse(array $json): array
    {
        $competition = is_array($json['header']['competitions'][0] ?? null) ? $json['header']['competitions'][0] : [];
        $sides = [];
        foreach ((array) ($competition['competitors'] ?? []) as $competitor) {
            $side = is_array($competitor) ? ($competitor['homeAway'] ?? null) : null;
            if ($side === 'home' || $side === 'away') {
                $sides[$side] = $competitor;
            }
        }

        return [
            'line_score' => self::lineScore($sides, $json['format']['regulation'] ?? []),
            'events' => self::events($json, $sides),
            'stats' => self::stats($json),
            'leaders' => self::leaders($json, $sides),
            'info' => self::info($json, $sides),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $sides
     * @return array<string, list<string>>|null
     */
    private static function lineScore(array $sides, mixed $regulation): ?array
    {
        $scores = [];
        foreach (['home', 'away'] as $side) {
            $scores[$side] = [];
            foreach ((array) ($sides[$side]['linescores'] ?? []) as $line) {
                $scores[$side][] = is_array($line)
                    ? (self::string($line['displayValue'] ?? null) ?? self::string($line['value'] ?? null) ?? '-')
                    : '-';
            }
        }
        $count = max(count($scores['home']), count($scores['away']));
        if ($count === 0) {
            return null;
        }

        // Baseball's extra innings are just more innings
        $regular = is_array($regulation) && ($regulation['slug'] ?? null) !== 'inning'
            ? (int) ($regulation['periods'] ?? 0)
            : 0;
        $periods = [];
        for ($i = 1; $i <= $count; $i++) {
            $extra = $regular > 0 ? $i - $regular : 0;
            $periods[] = match (true) {
                $extra < 1 => (string) $i,
                $extra === 1 => 'OT',
                default => 'OT'.$extra,
            };
        }

        return [
            'periods' => $periods,
            'home' => array_pad($scores['home'], $count, '-'),
            'away' => array_pad($scores['away'], $count, '-'),
        ];
    }

    /**
     * Goals, cards and substitutions where ESPN marks them (soccer), else the
     * scoring plays, wherever the sport keeps those.
     *
     * @param  array<string, mixed>  $json
     * @param  array<string, array<string, mixed>>  $sides
     * @return list<array<string, mixed>>
     */
    private static function events(array $json, array $sides): array
    {
        $teamSides = [];
        foreach ($sides as $side => $competitor) {
            $teamId = self::string($competitor['team']['id'] ?? null);
            if ($teamId !== null) {
                $teamSides[$teamId] = $side;
            }
        }

        $plays = is_array($json['keyEvents'] ?? null) ? $json['keyEvents'] : null;
        if ($plays === null) {
            $plays = is_array($json['scoringPlays'] ?? null)
                ? $json['scoringPlays']
                : array_filter((array) ($json['plays'] ?? []), fn (mixed $play): bool => is_array($play) && ($play['scoringPlay'] ?? false) === true);
            if (count($plays) > self::MAX_SCORING_PLAYS) {
                return [];
            }
        }

        $events = [];
        foreach ($plays as $play) {
            if (! is_array($play) || ($kind = self::eventKind($play)) === null) {
                continue;
            }
            $text = self::string($play['text'] ?? null) ?? self::string($play['shortText'] ?? null);
            if ($text === null) {
                continue;
            }
            $homeScore = self::string($play['homeScore'] ?? null);
            $awayScore = self::string($play['awayScore'] ?? null);
            $events[] = [
                'kind' => $kind,
                'time' => self::eventTime($play),
                'side' => $teamSides[self::string($play['team']['id'] ?? null) ?? ''] ?? null,
                'text' => $text,
                'home_score' => $homeScore,
                'away_score' => $awayScore,
            ];
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $play
     */
    private static function eventKind(array $play): ?string
    {
        if (($play['scoringPlay'] ?? true) === true) {
            return 'score';
        }
        $type = strtolower((string) ($play['type']['type'] ?? ''));

        return match (true) {
            str_contains($type, 'yellow') => 'yellow_card',
            str_contains($type, 'red') => 'red_card',
            str_contains($type, 'substitution') => 'substitution',
            str_contains($type, 'penalty') => 'penalty',
            default => null,
        };
    }

    /**
     * "12'", "1st 19:47", "Q2 1:08", "Top 2nd Inning".
     *
     * @param  array<string, mixed>  $play
     */
    private static function eventTime(array $play): ?string
    {
        $clock = self::string($play['clock']['displayValue'] ?? null);
        if ($clock !== null && str_contains($clock, "'")) {
            return $clock;
        }
        $period = self::string($play['period']['displayValue'] ?? null);
        if ($period === null && ($number = self::string($play['period']['number'] ?? null)) !== null) {
            $period = 'Q'.$number;
        }
        $half = self::string($play['period']['type'] ?? null);

        return trim(implode(' ', array_filter([$half, $period, $clock]))) ?: null;
    }

    /**
     * Each statistic both teams report, in ESPN's order.
     *
     * @param  array<string, mixed>  $json
     * @return list<array<string, string>>
     */
    private static function stats(array $json): array
    {
        $bySide = [];
        foreach ((array) ($json['boxscore']['teams'] ?? []) as $team) {
            $side = is_array($team) ? ($team['homeAway'] ?? null) : null;
            if ($side === 'home' || $side === 'away') {
                $bySide[$side] = self::teamStats((array) ($team['statistics'] ?? []));
            }
        }

        $stats = [];
        foreach ($bySide['home'] ?? [] as $key => [$label, $home]) {
            $away = $bySide['away'][$key][1] ?? null;
            if ($away !== null) {
                $stats[] = ['label' => $label, 'home' => $home, 'away' => $away];
            }
        }

        return $stats;
    }

    /**
     * @param  array<mixed>  $statistics
     * @return array<string, array{0: string, 1: string}> label and value, by stat name
     */
    private static function teamStats(array $statistics): array
    {
        $stats = [];
        foreach ($statistics as $stat) {
            if (! is_array($stat)) {
                continue;
            }
            if (is_array($stat['stats'] ?? null)) {
                $wanted = self::GROUPED_STATS[$stat['name'] ?? ''] ?? [];
                foreach ($stat['stats'] as $grouped) {
                    if (is_array($grouped) && in_array($grouped['name'] ?? null, $wanted, true)) {
                        $stats += self::oneStat($grouped);
                    }
                }

                continue;
            }
            $stats += self::oneStat($stat);
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $stat
     * @return array<string, array{0: string, 1: string}>
     */
    private static function oneStat(array $stat): array
    {
        $name = self::string($stat['name'] ?? null);
        $label = self::string($stat['label'] ?? null) ?? self::string($stat['displayName'] ?? null) ?? $name;
        $value = self::string($stat['displayValue'] ?? null);
        if ($name === null || $label === null || $value === null) {
            return [];
        }
        // Soccer's shot labels arrive as "SHOTS" and "ON GOAL"
        if ($label === strtoupper($label) && preg_match('/[A-Z]{4}/', $label) === 1) {
            $label = ucwords(strtolower($label));
        }

        return [$name => [$label, $value]];
    }

    /**
     * Each team's top player per category, for the categories either has.
     *
     * @param  array<string, mixed>  $json
     * @param  array<string, array<string, mixed>>  $sides
     * @return list<array<string, mixed>>
     */
    private static function leaders(array $json, array $sides): array
    {
        $teamSides = [];
        foreach ($sides as $side => $competitor) {
            $teamId = self::string($competitor['team']['id'] ?? null);
            if ($teamId !== null) {
                $teamSides[$teamId] = $side;
            }
        }

        $categories = [];
        foreach ((array) ($json['leaders'] ?? []) as $team) {
            $side = is_array($team) ? ($teamSides[self::string($team['team']['id'] ?? null) ?? ''] ?? null) : null;
            if ($side === null) {
                continue;
            }
            foreach ((array) ($team['leaders'] ?? []) as $category) {
                $name = is_array($category) ? self::string($category['name'] ?? null) : null;
                $top = is_array($category['leaders'][0] ?? null) ? $category['leaders'][0] : null;
                $athlete = self::string($top['athlete']['displayName'] ?? null);
                if ($name === null || $athlete === null) {
                    continue;
                }
                $categories[$name] ??= ['label' => self::string($category['displayName'] ?? null) ?? $name, 'home' => null, 'away' => null];
                $categories[$name][$side] = [
                    'name' => $athlete,
                    'value' => self::string($top['displayValue'] ?? null),
                    'photo' => self::string($top['athlete']['headshot']['href'] ?? null),
                ];
            }
        }

        return array_slice(array_values($categories), 0, self::MAX_LEADER_CATEGORIES);
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  array<string, array<string, mixed>>  $sides
     * @return array<string, mixed>
     */
    private static function info(array $json, array $sides): array
    {
        $game = is_array($json['gameInfo'] ?? null) ? $json['gameInfo'] : [];
        $address = is_array($game['venue']['address'] ?? null) ? $game['venue']['address'] : [];
        $location = array_filter([self::string($address['city'] ?? null), self::string($address['state'] ?? null) ?? self::string($address['country'] ?? null)]);

        $officials = [];
        foreach ((array) ($game['officials'] ?? []) as $official) {
            $name = is_array($official) ? self::string($official['displayName'] ?? null) : null;
            if ($name !== null) {
                $officials[] = $name;
            }
        }

        $series = null;
        foreach ((array) ($json['seasonseries'] ?? []) as $entry) {
            $series ??= is_array($entry) ? self::string($entry['summary'] ?? null) : null;
        }

        $attendance = is_numeric($game['attendance'] ?? null) ? (int) $game['attendance'] : 0;

        return [
            'venue' => self::string($game['venue']['fullName'] ?? null),
            'location' => $location === [] ? null : implode(', ', $location),
            'attendance' => $attendance > 0 ? $attendance : null,
            'officials' => $officials,
            'series' => $series,
            'home_record' => self::record($sides['home'] ?? []),
            'away_record' => self::record($sides['away'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $competitor
     */
    private static function record(array $competitor): ?string
    {
        foreach ((array) ($competitor['record'] ?? []) as $record) {
            if (is_array($record) && ($record['type'] ?? null) === 'total') {
                return self::string($record['summary'] ?? null);
            }
        }

        return null;
    }
}
