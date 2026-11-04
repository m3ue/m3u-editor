<?php

namespace App\Services\Sports;

use App\Services\EspnSportsService;
use App\Services\TheSportsDbService;
use App\Settings\GeneralSettings;
use Carbon\CarbonInterface;

/**
 * Sports data, whichever providers are turned on: everything outside the
 * providers reads through here, by league id.
 *
 * Each league is served by one provider, so a game never appears twice: ESPN
 * while it's on, TheSportsDB for what ESPN doesn't carry. A league both carry
 * has a different id on each, so its other id still resolves: an AED profile or
 * a TV's saved pick keeps working when a provider is turned off.
 */
class SportsService
{
    use MapsSportsData;

    public const int MAX_RANGE_DAYS = SportsProvider::MAX_RANGE_DAYS;

    /** @var array<string, true> */
    private array $leagueScope = [];

    /** @var array<int, mixed>|null What [$leagueScope] was worked out from */
    private ?array $leagueScopeSignature = null;

    public function __construct(
        private readonly EspnSportsService $espn,
        private readonly TheSportsDbService $theSportsDb,
        private readonly GeneralSettings $settings,
    ) {}

    public function isEnabled(): bool
    {
        return $this->espn->isEnabled() || $this->theSportsDb->isEnabled();
    }

    /**
     * The competitions clients may list, as sent to them.
     *
     * @return list<array{id: string, name: string, abbreviation: string, sport: string, sport_name: string, soccer_style: bool, default: bool}>
     */
    public function leagues(): array
    {
        $leagues = $this->offeredLeagues($this->espn->isEnabled(), $this->theSportsDb->isEnabled());
        $scope = $this->leagueScope();
        if ($scope === null) {
            return $leagues;
        }

        // Limiting Sports to some leagues says those are the ones wanted, so
        // clients show all of them rather than only the catalogue's defaults.
        $scoped = [];
        foreach ($leagues as $league) {
            if (isset($scope[$league['id']])) {
                $scoped[] = [...$league, 'default' => true];
            }
        }

        return $scoped;
    }

    /**
     * The competitions the given providers carry, ESPN's serving any both do.
     *
     * @return list<array{id: string, name: string, abbreviation: string, sport: string, sport_name: string, soccer_style: bool, default: bool}>
     */
    private function offeredLeagues(bool $espnEnabled, bool $theSportsDbEnabled): array
    {
        $leagues = $espnEnabled ? EspnSportsService::leagues() : [];
        if ($theSportsDbEnabled) {
            foreach (TheSportsDbService::leagues() as $league) {
                if (! $espnEnabled || ! isset(TheSportsDbService::findLeague($league['id'])['espn'])) {
                    $leagues[] = $league;
                }
            }
        }

        return $leagues;
    }

    /**
     * The leagues Settings limits Sports to, as the ids serving them, so a
     * league picked from one provider stays picked when the other takes it
     * over. Null when it's not limited.
     *
     * @return array<string, true>|null
     */
    private function leagueScope(): ?array
    {
        $leagueIds = $this->settings->sports_league_ids ?: [];
        if ($leagueIds === []) {
            return null;
        }

        $signature = [$leagueIds, $this->espn->isEnabled(), $this->theSportsDb->isEnabled()];
        if ($signature !== $this->leagueScopeSignature) {
            $this->leagueScopeSignature = $signature;
            $this->leagueScope = [];
            foreach ($leagueIds as $leagueId) {
                $served = $this->servedLeagueId($leagueId);
                if ($served !== null) {
                    $this->leagueScope[$served] = true;
                }
            }
        }

        return $this->leagueScope;
    }

    /**
     * Leagues to pick from in a form, by sport. With every provider off it
     * lists ESPN's, so a profile can still be set up ahead of turning one on.
     * [$selected] ids no longer offered stay listed, or the saved form would
     * fail validation when a provider changes.
     *
     * @param  list<string>  $selected
     * @return array<string, array<string, string>>
     */
    public function leagueOptions(array $selected = []): array
    {
        return $this->groupLeagueOptions($this->isEnabled() ? $this->leagues() : EspnSportsService::leagues(), $selected);
    }

    /**
     * Leagues to limit Sports to in Settings: all that the providers switched
     * on in the form carry, whatever is limited now.
     *
     * @param  list<string>  $selected
     * @return array<string, array<string, string>>
     */
    public function leagueScopeOptions(bool $espnEnabled, bool $theSportsDbEnabled, array $selected = []): array
    {
        return $this->groupLeagueOptions($this->offeredLeagues($espnEnabled, $theSportsDbEnabled), $selected);
    }

    /**
     * @param  list<array<string, mixed>>  $leagues
     * @param  list<string>  $selected
     * @return array<string, array<string, string>>
     */
    private function groupLeagueOptions(array $leagues, array $selected): array
    {
        $options = [];
        foreach ($leagues as $league) {
            $options[$league['sport_name']][$league['id']] = $league['name'];
        }
        foreach ($selected as $leagueId) {
            $league = EspnSportsService::findLeague($leagueId) ?? TheSportsDbService::findLeague($leagueId);
            if ($league !== null) {
                $options[self::sportName($league['sport'])][$leagueId] ??= $league['name'];
            }
        }

        return $options;
    }

    /**
     * [leagues] as sent to clients on every login, kept small (the Xtream API
     * isn't compressed): flags only when set, an abbreviation only when it
     * differs from the name, and no sport, which starts the id.
     *
     * @return list<array{id: string, name: string, abbreviation?: string, soccer_style?: true, default?: true}>
     */
    public function clientLeagues(): array
    {
        return array_map(fn (array $league): array => array_filter([
            'id' => $league['id'],
            'name' => $league['name'],
            'abbreviation' => $league['abbreviation'] !== $league['name'] ? $league['abbreviation'] : null,
            'soccer_style' => $league['soccer_style'] ?: null,
            'default' => $league['default'] ?: null,
        ], fn (mixed $value): bool => $value !== null), $this->leagues());
    }

    /**
     * Each sport's display name, by the sport that starts a league id.
     *
     * @return array<string, string>
     */
    public function sportNames(): array
    {
        return array_column($this->leagues(), 'sport_name', 'sport');
    }

    /**
     * The catalogue entry serving [$leagueId], or null when no provider that's
     * on carries it. Ignores the league limit, so a team followed before a
     * league was left out can still be unfollowed.
     *
     * @return array<string, mixed>|null
     */
    public function findLeague(string $leagueId): ?array
    {
        $served = $this->servedLeagueId($leagueId);
        if ($served === null) {
            return null;
        }

        return TheSportsDbService::owns($served)
            ? TheSportsDbService::findLeague($served)
            : EspnSportsService::findLeague($served);
    }

    /**
     * @param  list<string>  $leagueIds
     * @return list<array<string, mixed>>
     */
    public function fixtures(array $leagueIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $fixtures = [];
        foreach ($this->byProvider($leagueIds) as [$provider, $served]) {
            array_push($fixtures, ...$provider->fixtures($served, $from, $to));
        }

        return $this->soonestFirst($fixtures);
    }

    /**
     * @param  list<string>  $leagueIds
     * @return list<array<string, mixed>>
     */
    public function rawFixtures(array $leagueIds, CarbonInterface $from, CarbonInterface $to, bool $liveScores = true): array
    {
        $fixtures = [];
        foreach ($this->byProvider($leagueIds) as [$provider, $served]) {
            array_push($fixtures, ...$provider->rawFixtures($served, $from, $to, $liveScores));
        }

        return $this->soonestFirst($fixtures);
    }

    /**
     * @param  list<string>  $leagueIds
     * @return array{fixtures: list<array<string, mixed>>, complete: bool}
     */
    public function cachedFixtures(array $leagueIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $fixtures = [];
        $complete = true;
        foreach ($this->byProvider($leagueIds) as [$provider, $served]) {
            $cached = $provider->cachedFixtures($served, $from, $to);
            array_push($fixtures, ...$cached['fixtures']);
            $complete = $complete && $cached['complete'];
        }

        return ['fixtures' => $this->soonestFirst($fixtures), 'complete' => $complete];
    }

    /**
     * @param  list<string>  $leagueIds
     */
    public function forgetFixtures(array $leagueIds, CarbonInterface $from, CarbonInterface $to): void
    {
        foreach ($this->byProvider($leagueIds) as [$provider, $served]) {
            $provider->forgetFixtures($served, $from, $to);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function teams(string $leagueId): array
    {
        foreach ($this->byProvider([$leagueId]) as [$provider, $served]) {
            return $provider->teams($served[0]);
        }

        return [];
    }

    /**
     * @param  list<string>  $leagueIds
     * @return array<string, list<array<string, mixed>>>
     */
    public function teamsByLeague(array $leagueIds, bool $fetchMissing = true): array
    {
        $teams = [];
        foreach ($this->byProvider($leagueIds) as [$provider, $served]) {
            $teams += $provider->teamsByLeague($served, $fetchMissing);
        }

        return $teams;
    }

    /**
     * [$leagueIds] split by the provider serving each. Unknown ones are dropped,
     * and so are any outside the league limit, so nothing is fetched for them
     * whoever asks (an older AED profile, a TV's saved picks).
     *
     * @param  list<string>  $leagueIds
     * @return list<array{0: SportsProvider, 1: list<string>}>
     */
    private function byProvider(array $leagueIds): array
    {
        $scope = $this->leagueScope();
        $espn = [];
        $theSportsDb = [];
        foreach ($leagueIds as $leagueId) {
            $served = $this->servedLeagueId($leagueId);
            if ($served === null || ($scope !== null && ! isset($scope[$served]))) {
                continue;
            }
            if (TheSportsDbService::owns($served)) {
                $theSportsDb[$served] = true;
            } else {
                $espn[$served] = true;
            }
        }

        return array_values(array_filter([
            $espn !== [] ? [$this->espn, array_keys($espn)] : null,
            $theSportsDb !== [] ? [$this->theSportsDb, array_keys($theSportsDb)] : null,
        ]));
    }

    /**
     * The id [$leagueId] is served under right now, or null when no provider
     * that's on carries it.
     */
    private function servedLeagueId(string $leagueId): ?string
    {
        if (TheSportsDbService::owns($leagueId)) {
            $league = TheSportsDbService::findLeague($leagueId);
            if (isset($league['espn']) && $this->espn->isEnabled()) {
                return $league['espn'];
            }

            return $league !== null && $this->theSportsDb->isEnabled() ? $leagueId : null;
        }

        if (EspnSportsService::findLeague($leagueId) === null) {
            return null;
        }
        if ($this->espn->isEnabled()) {
            return $leagueId;
        }

        return $this->theSportsDb->isEnabled() ? TheSportsDbService::leagueIdForEspn($leagueId) : null;
    }

    /**
     * @param  list<array<string, mixed>>  $fixtures
     * @return list<array<string, mixed>>
     */
    private function soonestFirst(array $fixtures): array
    {
        usort($fixtures, fn (array $a, array $b): int => strcmp($a['start'], $b['start']));

        return $fixtures;
    }
}
