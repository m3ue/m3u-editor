<?php

namespace App\Filament\Pages;

use App\Filament\Tables\SportsLeaguesTable;
use App\Models\Channel;
use App\Models\Playlist;
use App\Services\Sports\SportsChannelMatcher;
use App\Services\Sports\SportsGameDisplay;
use App\Services\Sports\SportsService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Livewire\Attributes\Url;

/**
 * A day's games for one playlist: live scores, the channels carrying each game,
 * and a details slide-over with its stats and those channels ready to play.
 *
 * A cold fetch of many leagues can outlast a web request, so the page shows
 * first, then asks for the games, then for their channels (matching reads the
 * guide). A viewer's picks are capped at [MAX_LEAGUES] for the same reason.
 */
class SportsGames extends Page
{
    /** Picking more than this could outlast a web request on a cold cache. */
    private const int MAX_LEAGUES = 30;

    /** The same reach as the TV's date bar. */
    private const int DAYS_BACK = 7;

    private const int DAYS_AHEAD = 14;

    protected string $view = 'filament.pages.sports-games';

    protected static ?int $navigationSort = 2;

    #[Url]
    public ?int $playlistId = null;

    /** The day shown, Y-m-d in the app's time zone. */
    #[Url]
    public ?string $day = null;

    /**
     * Remembered between visits; null until there's been a first visit.
     *
     * @var list<string>|null
     */
    #[Session]
    public ?array $leagueIds = null;

    public bool $gamesLoaded = false;

    public bool $channelsLoaded = false;

    public static function getNavigationGroup(): ?string
    {
        return __('Playlist');
    }

    public static function getNavigationLabel(): string
    {
        return __('Sports');
    }

    public function getTitle(): string
    {
        return __('Sports');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Scores and stats for each game, and the channels in this playlist showing it.');
    }

    public static function canAccess(): bool
    {
        return auth()->check() && app(SportsService::class)->isEnabled();
    }

    public function mount(): void
    {
        $playlists = $this->userPlaylists();
        if (! $playlists->has($this->playlistId)) {
            $this->playlistId = $playlists->keys()->first();
        }
        $this->leagueIds = $this->rememberedLeagueIds();

        $this->filtersForm->fill([
            'playlistId' => $this->playlistId,
            'day' => $this->dayStart()->format('Y-m-d'),
            'leagueIds' => $this->leagueIds,
        ]);
        $this->normalizeDay();
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->statePath(null)
            ->components([
                Select::make('playlistId')
                    ->label(__('Playlist'))
                    ->options(fn (): array => $this->userPlaylists()->all())
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->hint(fn (): ?string => $this->gamesLoaded && ! $this->channelsLoaded && count($this->games) > 0 ? __('Finding channels') : null)
                    ->live(),
                DatePicker::make('day')
                    ->label(__('Date'))
                    ->native(false)
                    ->format('Y-m-d')
                    ->closeOnDateSelection()
                    ->minDate(fn (): CarbonImmutable => $this->today()->subDays(self::DAYS_BACK))
                    ->maxDate(fn (): CarbonImmutable => $this->today()->addDays(self::DAYS_AHEAD))
                    ->prefixActions([
                        Action::make('previousDay')
                            ->label(__('Previous day'))
                            ->icon('heroicon-m-chevron-left')
                            ->color('gray')
                            ->action(fn () => $this->shiftDay(-1)),
                    ])
                    ->suffixActions([
                        Action::make('today')
                            ->label(__('Today'))
                            ->icon('heroicon-m-calendar')
                            ->color('gray')
                            ->action(fn () => $this->goToToday()),
                        Action::make('nextDay')
                            ->label(__('Next day'))
                            ->icon('heroicon-m-chevron-right')
                            ->color('gray')
                            ->action(fn () => $this->shiftDay(1)),
                    ])
                    ->live(),
                ModalTableSelect::make('leagueIds')
                    ->label(__('Leagues'))
                    ->tableConfiguration(SportsLeaguesTable::class)
                    ->multiple()
                    ->maxItems(self::MAX_LEAGUES)
                    // The button says how many; the table shows which they are
                    ->getOptionLabelsUsing(fn (): array => [])
                    ->selectAction(
                        fn (Action $action): Action => $action
                            ->label(function (): string {
                                $count = count($this->requestedLeagueIds());

                                return $count === 0
                                    ? __('Select leagues')
                                    : trans_choice('{1} :count league selected|[2,*] :count leagues selected', $count, ['count' => $count]);
                            })
                            ->modalHeading(__('Select leagues'))
                            ->modalSubmitActionLabel(__('Confirm selection'))
                            ->button()
                            ->color('gray')
                            ->extraAttributes(['class' => 'w-full']),
                    )
                    ->hintAction(
                        Action::make('clearLeagues')
                            ->label(__('Clear all'))
                            ->icon('heroicon-m-x-mark')
                            ->color('gray')
                            ->visible(fn (): bool => $this->hasLeagues())
                            ->action(function (): void {
                                $this->leagueIds = [];
                                $this->askForChannels();
                            }),
                    )
                    ->afterStateUpdated(fn () => $this->askForChannels()),
            ])
            ->columns(['default' => 1, 'md' => 3]);
    }

    /**
     * Anything that changes which games are on screen means finding channels
     * again.
     */
    public function updated(string $property): void
    {
        if (in_array(explode('.', $property)[0], ['playlistId', 'day', 'leagueIds'], true)) {
            $this->normalizeDay();
            $this->askForChannels();
        }
    }

    public function shiftDay(int $days): void
    {
        $this->goToDay($this->dayStart()->addDays($days));
    }

    public function goToToday(): void
    {
        $this->goToDay($this->today());
    }

    public function loadGames(): void
    {
        $this->gamesLoaded = true;
        $this->askForChannels();
    }

    public function loadChannels(): void
    {
        $this->channelsLoaded = true;
    }

    /**
     * Plays one of the channels found for this day's games.
     */
    public function playChannel(int $channelId): void
    {
        if (! collect($this->channels)->flatten(1)->contains('stream_id', $channelId)) {
            return;
        }

        $channel = Channel::query()->where('user_id', auth()->id())->find($channelId);
        if ($channel !== null) {
            $this->dispatch('openFloatingStream', $channel->getFloatingPlayerAttributes());
        }
    }

    public function gameDetailsAction(): Action
    {
        return Action::make('gameDetails')
            ->modalHeading(__('Game'))
            ->slideOver()
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->modalContent(fn (array $arguments): View => view(
                'filament.pages.sports-games.details',
                $this->detailsViewData((string) ($arguments['league'] ?? ''), (string) ($arguments['fixture'] ?? '')),
            ));
    }

    /**
     * The games to show, soonest first.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function games(): array
    {
        $leagueIds = $this->requestedLeagueIds();
        if (! $this->gamesLoaded || $leagueIds === []) {
            return [];
        }

        $from = $this->dayStart();

        return app(SportsService::class)->fixtures($leagueIds, $from->utc(), $from->addDay()->utc());
    }

    /**
     * The playlist's channels for each of [games], by `{league id}:{game id}`.
     *
     * @return array<string, list<array{stream_id: int, program_title: string|null}>>
     */
    #[Computed]
    public function channels(): array
    {
        $playlist = $this->playlist();
        $leagueIds = $this->requestedLeagueIds();
        if (! $this->channelsLoaded || $playlist === null || $leagueIds === []) {
            return [];
        }

        $from = $this->dayStart();

        return app(SportsChannelMatcher::class)->channelsFor($playlist, $leagueIds, $from->utc(), $from->addDay()->utc());
    }

    /**
     * Games under way, then the rest under their league, in the catalogue's order.
     *
     * @return array{live: list<array<string, mixed>>, leagues: list<array{name: string, games: list<array<string, mixed>>}>}
     */
    #[Computed]
    public function gameGroups(): array
    {
        $live = [];
        $byLeague = [];
        foreach ($this->games as $game) {
            if ($game['state'] === 'live') {
                $live[] = $game;
            } else {
                $byLeague[$game['league_id']][] = $game;
            }
        }

        $leagues = [];
        foreach ($this->servedLeagues() as $league) {
            if (isset($byLeague[$league['id']])) {
                $leagues[] = ['name' => $league['name'], 'games' => $byLeague[$league['id']]];
            }
        }

        return ['live' => $live, 'leagues' => $leagues];
    }

    /**
     * Short league names by id, for a card outside its league's group.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function leagueNames(): array
    {
        return array_column($this->servedLeagues(), 'abbreviation', 'id');
    }

    public function hasLeagues(): bool
    {
        return $this->requestedLeagueIds() !== [];
    }

    /**
     * What the details slide-over shows. The game comes from its details when
     * those can be read, else as the list has it.
     *
     * @return array<string, mixed>
     */
    private function detailsViewData(string $leagueId, string $fixtureId): array
    {
        $listed = collect($this->games)->first(
            fn (array $game): bool => $game['league_id'] === $leagueId && $game['id'] === $fixtureId,
        );
        $details = preg_match('/^[A-Za-z0-9._-]+$/', $fixtureId) === 1
            ? app(SportsService::class)->fixtureDetails($leagueId, $fixtureId)
            : null;
        $game = $details === null ? $listed : [
            ...($listed ?? []),
            ...$details['fixture'],
            'broadcasts' => $details['fixture']['broadcasts'] ?: ($listed['broadcasts'] ?? []),
            'league_logo' => $details['fixture']['league_logo'] ?? ($listed['league_logo'] ?? null),
        ];

        $watchable = $game !== null && SportsGameDisplay::isWatchable($game);
        $matches = $watchable ? ($this->channels[$leagueId.':'.$fixtureId] ?? []) : [];
        $channels = Channel::query()
            ->where('user_id', auth()->id())
            ->whereIn('id', array_column($matches, 'stream_id'))
            ->get()
            ->keyBy('id');

        return [
            'game' => $game,
            'details' => $details,
            'leagueName' => collect($this->servedLeagues())->firstWhere('id', $leagueId)['name'] ?? '',
            'showsChannels' => $watchable,
            'isFindingChannels' => $watchable && ! $this->channelsLoaded,
            'channelRows' => collect($matches)
                ->filter(fn (array $match): bool => $channels->has($match['stream_id']))
                ->map(fn (array $match): array => [
                    'channel' => $channels[$match['stream_id']],
                    'program_title' => $match['program_title'],
                ])
                ->values()
                ->all(),
        ];
    }

    private function askForChannels(): void
    {
        $this->channelsLoaded = false;
        $this->js('$wire.loadChannels()');
    }

    /**
     * Date pickers keep a time on their state; the URL only needs the day.
     */
    private function normalizeDay(): void
    {
        $this->day = $this->dayStart()->format('Y-m-d');
    }

    private function goToDay(CarbonImmutable $day): void
    {
        if (! $this->isWithinReach($day)) {
            return;
        }
        $this->day = $day->format('Y-m-d');
        $this->askForChannels();
    }

    /**
     * The selected leagues Sports still serves, at most [MAX_LEAGUES].
     *
     * @return list<string>
     */
    private function requestedLeagueIds(): array
    {
        $servedIds = array_column($this->servedLeagues(), 'id');

        return array_slice(array_intersect((array) $this->leagueIds, $servedIds), 0, self::MAX_LEAGUES);
    }

    /**
     * The picks from an earlier visit, less any Settings has since left out.
     * First visits, and picks Settings has left out entirely, get the defaults;
     * having cleared them all stays that way.
     *
     * @return list<string>
     */
    private function rememberedLeagueIds(): array
    {
        if ($this->leagueIds === []) {
            return [];
        }
        $kept = $this->requestedLeagueIds();

        return $kept === [] ? $this->defaultLeagueIds() : $kept;
    }

    /**
     * Every league Sports serves, within any limit set in Settings.
     *
     * @return list<array<string, mixed>>
     */
    private function servedLeagues(): array
    {
        return app(SportsService::class)->leagues();
    }

    /**
     * The leagues Sports lists by default, or none when those are too many to
     * fetch at once (a limit in Settings makes all of them default).
     *
     * @return list<string>
     */
    private function defaultLeagueIds(): array
    {
        $defaultIds = array_column(
            array_filter($this->servedLeagues(), fn (array $league): bool => $league['default']),
            'id',
        );

        return count($defaultIds) <= self::MAX_LEAGUES ? $defaultIds : [];
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'))->startOfDay();
    }

    /**
     * The start of the selected day, or of today when that isn't a day within
     * reach.
     */
    private function dayStart(): CarbonImmutable
    {
        if (
            ! is_string($this->day)
            || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $this->day, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        ) {
            return $this->today();
        }
        $day = CarbonImmutable::parse($parts[0], config('app.timezone'))->startOfDay();

        return $this->isWithinReach($day) ? $day : $this->today();
    }

    private function isWithinReach(CarbonImmutable $day): bool
    {
        $today = $this->today();

        return $day->between($today->subDays(self::DAYS_BACK), $today->addDays(self::DAYS_AHEAD));
    }

    private function playlist(): ?Playlist
    {
        return Playlist::query()->where('user_id', auth()->id())->find($this->playlistId);
    }

    /**
     * @return Collection<int, string>
     */
    private function userPlaylists(): Collection
    {
        return Playlist::query()
            ->where('user_id', auth()->id())
            ->orderBy('name')
            ->pluck('name', 'id');
    }
}
