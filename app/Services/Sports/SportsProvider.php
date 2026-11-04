<?php

namespace App\Services\Sports;

use Carbon\CarbonInterface;

/**
 * A source of game schedules, scores and team lists. Leagues, fixtures and
 * teams all take one shape whichever provider they came from, so
 * [SportsService] and everything above it (the TV's Sports screen, sports AED
 * profiles) never ask where they did.
 *
 * A league id is "{sport}/{slug}", and a team key "{league id}:{team id}".
 */
interface SportsProvider
{
    /** Longest span one fixtures request may cover. */
    public const int MAX_RANGE_DAYS = 14;

    public function isEnabled(): bool;

    /**
     * The competitions this provider offers.
     *
     * @return list<array{id: string, name: string, abbreviation: string, sport: string, sport_name: string, soccer_style: bool, default: bool}>
     */
    public static function leagues(): array;

    /**
     * The catalogue entry for [$leagueId], or null when it isn't offered.
     *
     * @return array<string, mixed>|null
     */
    public static function findLeague(string $leagueId): ?array;

    /**
     * Every fixture of [$leagueIds] starting in [$from, $to), soonest first,
     * ready to send to a client (crests through the logo proxy).
     *
     * @param  list<string>  $leagueIds
     * @return list<array<string, mixed>>
     */
    public function fixtures(array $leagueIds, CarbonInterface $from, CarbonInterface $to): array;

    /**
     * [fixtures] for server-side use, with crests as the provider serves them.
     * Without [$liveScores], scores may be stale: game matching only reads
     * kickoffs and teams.
     *
     * @param  list<string>  $leagueIds
     * @return list<array<string, mixed>>
     */
    public function rawFixtures(array $leagueIds, CarbonInterface $from, CarbonInterface $to, bool $liveScores = true): array;

    /**
     * [rawFixtures] from the cache alone, for EPG requests, which must never
     * wait on a provider. `complete` is false when part of the span isn't
     * cached yet.
     *
     * @param  list<string>  $leagueIds
     * @return array{fixtures: list<array<string, mixed>>, complete: bool}
     */
    public function cachedFixtures(array $leagueIds, CarbonInterface $from, CarbonInterface $to): array;

    /**
     * Drops what [rawFixtures] would read from the cache for these leagues and
     * this span, so the next read fetches it.
     *
     * @param  list<string>  $leagueIds
     */
    public function forgetFixtures(array $leagueIds, CarbonInterface $from, CarbonInterface $to): void;

    /**
     * Every team of [$leagueId], with crests through the logo proxy.
     *
     * @return list<array<string, mixed>>
     */
    public function teams(string $leagueId): array;

    /**
     * The teams of each of [$leagueIds], by league, with crests as the
     * provider serves them. Without [$fetchMissing] (an EPG request), lists
     * that aren't cached are left out.
     *
     * @param  list<string>  $leagueIds
     * @return array<string, list<array<string, mixed>>>
     */
    public function teamsByLeague(array $leagueIds, bool $fetchMissing = true): array;
}
