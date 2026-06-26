<?php

namespace App\Filament\Pages;

use App\Enums\DvrRuleType;
use App\Enums\DvrSeriesMode;
use App\Filament\Concerns\HasBrowseShowsFiltersForm;
use App\Jobs\DvrSchedulerTick;
use App\Models\Channel;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use App\Models\Group;
use App\Services\ShowMetadataService;
use App\Settings\GeneralSettings;
use App\Support\EpgProgrammeNormalizer;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

class BrowseShows extends Page
{
    use HasBrowseShowsFiltersForm;

    protected string $view = 'filament.pages.browse-shows';

    public static function getNavigationGroup(): ?string
    {
        return __('DVR');
    }

    public static function getNavigationLabel(): string
    {
        return __('Browse Shows');
    }

    public function getTitle(): string
    {
        return __('Browse Shows');
    }

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->canUseDvr();
    }

    private const PER_PAGE = 20;

    // --- Filter state ---

    public ?int $dvr_setting_id = null;

    public string $keyword = '';

    public string $category = '';

    public string $description_keyword = '';

    public ?int $group_id = null;

    public string $channel_name = '';

    public ?int $channel_id = null;

    public int $days = 14;

    // --- Result state ---

    public bool $searched = false;

    public bool $postersLoaded = false;

    /** @var array<int, array<string, mixed>> */
    public array $shows = [];

    public int $totalShows = 0;

    public int $currentPage = 1;

    // --- Slide-over ---

    public string $selectedShowTitle = '';

    /** @var array<string, mixed>|null */
    public ?array $selectedShowDetail = null;

    // --- Series options form state ---

    public int $seriesNewOnly = 0;

    /** 0 = "From Original Source" (sentinel), null = "Any channel", int = specific channel */
    public int $seriesChannelId = 0;

    /** The actual channel ID the show was browsed from (null if not resolved). */
    public ?int $sourceChannelId = null;

    public int $seriesPriority = 50;

    public int $seriesStartEarly = 0;

    public int $seriesEndLate = 0;

    public ?int $seriesKeepLast = null;

    // --- DVR setting cache (per-request) ---

    private ?DvrSetting $cachedDvrSetting = null;

    private bool $dvrSettingResolved = false;

    // --- Computed helpers ---

    public function getTimezoneNotSetProperty(): bool
    {
        return empty(config('dev.timezone')) && empty(app(GeneralSettings::class)->app_timezone);
    }

    public function getTotalPagesProperty(): int
    {
        return (int) ceil($this->totalShows / self::PER_PAGE);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->statePath(null)
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2, 'lg' => 3])->schema([
                    Select::make('dvr_setting_id')
                        ->label(__('DVR Setting (Playlist)'))
                        ->placeholder(__('No DVR settings configured'))
                        ->searchable()
                        ->options(fn () => DvrSetting::with('playlist')
                            ->where('user_id', Auth::id())
                            ->get()
                            ->mapWithKeys(fn (DvrSetting $s) => [$s->id => $s->playlist?->name ?? "DVR #{$s->id}"])
                            ->all())
                        ->live()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('group_id', null);
                            $set('channel_id', null);
                            $this->channelOptionsDispatched = false;
                            $this->dvrSettingResolved = false;
                            $this->cachedDvrSetting = null;
                        }),

                    $this->keywordFilterField(),
                    $this->categoryFilterField(),
                    $this->descriptionKeywordFilterField(),

                    $this->groupFilterField()
                        ->options(fn (Get $get): array => ($dvrSettingId = $get('dvr_setting_id'))
                            ? Group::where('playlist_id', DvrSetting::find($dvrSettingId)?->playlist_id ?? 0)
                                ->where([
                                    ['name', '!=', ''],
                                    ['name', '!=', null],
                                ])
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all()
                            : [])
                        ->searchable()
                        ->disabled(fn (Get $get): bool => ! $get('dvr_setting_id')),

                    $this->channelFilterField()
                        ->disabled(fn (Get $get): bool => ! $get('dvr_setting_id')),

                    $this->daysFilterField(),
                ]),
            ]);
    }

    /**
     * True once channels have been dispatched to the browser for this DVR setting.
     * Prevents redundant DB queries on repeat openings of Advanced options.
     * Reset when dvr_setting_id changes.
     */
    public bool $channelOptionsDispatched = false;

    /** Channel name of the resolved source channel for the current detail view. */
    public ?string $sourceChannelName = null;

    /** Channel name of a specifically-selected series channel (resolved on updatedSeriesChannelId). */
    public ?string $seriesChannelName = null;

    public function loadChannelOptions(): void
    {
        if ($this->channelOptionsDispatched || ! $this->dvr_setting_id) {
            return;
        }

        $playlistId = $this->getCachedDvrSetting()?->playlist_id;
        if (! $playlistId) {
            return;
        }

        $channels = Channel::where('playlist_id', $playlistId)
            ->select(['id', 'title', 'title_custom', 'name', 'name_custom']);
        if (! $this->shouldIncludeDisabledChannels()) {
            $channels->where('enabled', true);
        }

        $options = $channels->get()
            ->mapWithKeys(function (Channel $c) {
                return [$c->id => $c->title_custom ?: $c->title ?: $c->name_custom ?: $c->name];
            })
            ->sortBy(fn (string $label) => mb_strtolower($label))
            ->all();

        $this->channelOptionsDispatched = true;
        $this->dispatch('channel-options-loaded', dvr_setting_id: $this->dvr_setting_id, options: $options);
    }

    public function updatedSeriesChannelId(): void
    {
        $this->seriesChannelName = $this->seriesChannelId > 0
            ? $this->resolveChannelName($this->seriesChannelId)
            : null;
    }

    // --- Lifecycle ---

    public function mount(): void
    {
        $this->dvr_setting_id = DvrSetting::where('user_id', Auth::id())->orderBy('id')->value('id');

        $this->filtersForm->fill([
            'dvr_setting_id' => $this->dvr_setting_id,
            'keyword' => $this->keyword,
            'category' => $this->category,
            'description_keyword' => $this->description_keyword,
            'group_id' => $this->group_id,
            'channel_id' => $this->channel_id,
            'days' => $this->days,
        ]);
    }

    public function getSeriesHintProperty(): string
    {
        $channelName = match (true) {
            $this->seriesChannelId === 0 => $this->sourceChannelName
                ? Str::limit($this->sourceChannelName, 18)
                : __('original source'),
            $this->seriesChannelId > 0 => $this->seriesChannelName
                ? Str::limit($this->seriesChannelName, 18)
                : __('channel').' #'.$this->seriesChannelId,
            default => __('any channel'),
        };

        $parts = array_filter([
            $channelName,
            $this->seriesNewOnly ? __('new only') : null,
            $this->seriesPriority !== 50 ? 'P:'.$this->seriesPriority : null,
            ($this->seriesStartEarly || $this->seriesEndLate)
                ? '+'.$this->seriesStartEarly.'s/+'.$this->seriesEndLate.'s'
                : null,
            $this->seriesKeepLast ? __('keep').' '.$this->seriesKeepLast : null,
        ]);

        return implode(' · ', $parts);
    }

    // --- Slide-over actions ---

    // --- Search & pagination ---

    public function search(): void
    {
        $this->searched = true;
        $this->postersLoaded = false;
        $this->currentPage = 1;
        $this->loadPage();
        $this->dispatch('start-poster-load');
    }

    public function gotoPage(int $page): void
    {
        $this->currentPage = max(1, min($page, $this->totalPages));
        $this->postersLoaded = false;
        $this->loadPage();
        $this->dispatch('start-poster-load');
    }

    #[On('start-poster-load')]
    public function loadPosters(): void
    {
        if (empty($this->shows)) {
            $this->postersLoaded = true;

            return;
        }

        $titles = array_column($this->shows, 'title');
        $posterUrls = app(ShowMetadataService::class)->resolvePosters($titles);

        foreach ($this->shows as $index => $show) {
            $this->shows[$index]['poster_url'] = $posterUrls[$show['title']] ?? null;
        }

        $this->postersLoaded = true;
    }

    // --- Slide-over ---

    public function openShowDetail(string $title): void
    {
        $this->selectedShowTitle = $title;
        $this->seriesNewOnly = 0;
        $this->seriesPriority = 50;
        $this->seriesStartEarly = 0;
        $this->seriesEndLate = 0;
        $this->seriesKeepLast = null;
        [$this->sourceChannelId, $this->sourceChannelName] = $this->resolveSourceChannel($title);
        $this->seriesChannelId = 0;
        $this->seriesChannelName = null;
        $this->selectedShowDetail = $this->buildShowDetail($title);
    }

    public function closeShowDetail(): void
    {
        $this->selectedShowTitle = '';
        $this->selectedShowDetail = null;
    }

    // --- Recording actions ---

    public function recordOnce(int $programmeId): void
    {
        $dvrSetting = $this->getCachedDvrSetting();

        if (! $dvrSetting) {
            Notification::make()->title(__('Select a DVR Setting first.'))->warning()->send();

            return;
        }

        $programme = EpgProgramme::find($programmeId);

        if (! $programme) {
            Notification::make()->title(__('Programme not found.'))->danger()->send();

            return;
        }

        $exists = DvrRecordingRule::where('user_id', Auth::id())
            ->where('dvr_setting_id', $this->dvr_setting_id)
            ->where('type', DvrRuleType::Once)
            ->where('programme_id', $programmeId)
            ->exists();

        if ($exists) {
            Notification::make()->title(__('A Once rule for this programme already exists.'))->warning()->send();

            return;
        }

        // epg_programmes.epg_channel_id is the string channel ID from the EPG XML;
        // channels.epg_channel_id is an integer FK to epg_channels.id — resolve via EpgChannel first.
        $channel = null;
        if ($programme->epg_channel_id) {
            $epgChannelId = EpgChannel::where('channel_id', $programme->epg_channel_id)->value('id');
            if ($epgChannelId) {
                $channel = Channel::where('user_id', Auth::id())
                    ->where('epg_channel_id', $epgChannelId)
                    ->first();
            }
        }

        DvrRecordingRule::create([
            'user_id' => Auth::id(),
            'dvr_setting_id' => $this->dvr_setting_id,
            'type' => DvrRuleType::Once,
            'programme_id' => $programmeId,
            'series_title' => $programme->title,
            'channel_id' => $channel?->id,
            'enabled' => true,
            'priority' => 50,
        ]);

        $this->refreshRuleBadgeForProgramme($programmeId, 'once');

        DvrSchedulerTick::dispatch();

        Notification::make()
            ->title(__('Once rule created for ":title"', ['title' => $programme->title]))
            ->success()
            ->send();
    }

    public function quickRecordNextAiring(string $title): void
    {
        if (! $this->dvr_setting_id) {
            Notification::make()->title(__('Select a DVR Setting first.'))->warning()->send();

            return;
        }

        // Use already-loaded detail airings if the slide-over is open for this show.
        if ($this->selectedShowTitle === $title && ! empty($this->selectedShowDetail['airings'])) {
            $this->recordOnce($this->selectedShowDetail['airings'][0]['id']);

            return;
        }

        // Otherwise fetch the first upcoming airing directly.
        $programme = $this->buildBaseQuery()
            ->where('title', $title)
            ->orderBy('start_time')
            ->first();

        if (! $programme) {
            Notification::make()
                ->title(__('No upcoming airings found for ":title"', ['title' => $title]))
                ->warning()
                ->send();

            return;
        }

        $this->recordOnce($programme->id);
    }

    public function recordSeriesDefaults(string $title): void
    {
        $dvrSetting = $this->getCachedDvrSetting();
        $seriesMode = $dvrSetting?->default_series_mode ?? DvrSeriesMode::UniqueSe;

        $this->createSeriesRule($title, [
            'series_mode' => $seriesMode,
            'new_only' => $seriesMode === DvrSeriesMode::NewFlag,
            'keep_last' => $dvrSetting?->default_series_keep_last,
            'priority' => 50,
            'source_channel_id' => $this->sourceChannelId,
        ]);
    }

    public function recordSeriesWithOptions(string $title): void
    {
        $channelId = null;
        $sourceChannelId = null;

        if ($this->seriesChannelId === 0) {
            $sourceChannelId = $this->sourceChannelId;
        } elseif ($this->seriesChannelId > 0) {
            $channelId = $this->seriesChannelId;
        }

        $this->createSeriesRule($title, [
            'new_only' => $this->seriesNewOnly,
            'series_mode' => $this->seriesNewOnly ? DvrSeriesMode::NewFlag : DvrSeriesMode::All,
            'channel_id' => $channelId,
            'source_channel_id' => $sourceChannelId,
            'priority' => $this->seriesPriority,
            'start_early_seconds' => $this->seriesStartEarly,
            'end_late_seconds' => $this->seriesEndLate,
            'keep_last' => $this->seriesKeepLast ?: null,
        ]);
    }

    // --- Internal ---

    /**
     * Find the channel (within the selected DVR setting's playlist) that a show most likely
     * airs on, and return its ID and display name together in a single final query.
     *
     * Combining the ID and name lookup avoids a second Channel::find() call in openShowDetail.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function resolveSourceChannel(string $title): array
    {
        $playlistId = $this->getCachedDvrSetting()?->playlist_id;
        if (! $playlistId) {
            return [null, null];
        }

        $programme = $this->buildBaseQuery()
            ->where('title', $title)
            ->orderBy('start_time')
            ->first(['epg_channel_id']);

        if (! $programme?->epg_channel_id) {
            return [null, null];
        }

        $epgChannelPk = EpgChannel::where('channel_id', $programme->epg_channel_id)->value('id');
        if (! $epgChannelPk) {
            return [null, null];
        }

        $channel = Channel::where('playlist_id', $playlistId)
            ->where('epg_channel_id', $epgChannelPk)
            ->first(['id', 'title', 'title_custom', 'name', 'name_custom']);

        if (! $channel) {
            return [null, null];
        }

        $name = $channel->title_custom ?: $channel->title ?: $channel->name_custom ?: $channel->name;

        return [$channel->id, $name ?: null];
    }

    private function getCachedDvrSetting(): ?DvrSetting
    {
        if (! $this->dvrSettingResolved) {
            $this->cachedDvrSetting = $this->resolvedDvrSetting();
            $this->dvrSettingResolved = true;
        }

        return $this->cachedDvrSetting;
    }

    /**
     * Returns null if none is selected or the setting doesn't belong to this user,
     * preventing cross-user data access via a manipulated dvr_setting_id.
     */
    private function resolvedDvrSetting(): ?DvrSetting
    {
        if (! $this->dvr_setting_id) {
            return null;
        }

        return DvrSetting::where('user_id', Auth::id())->find($this->dvr_setting_id);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function createSeriesRule(string $title, array $options): void
    {
        $dvrSetting = $this->getCachedDvrSetting();

        if (! $dvrSetting) {
            Notification::make()->title(__('Select a DVR Setting first.'))->warning()->send();

            return;
        }

        $normalizedTitle = mb_strtolower(EpgProgrammeNormalizer::cleanForSearch($title));
        $exists = DvrRecordingRule::where('user_id', Auth::id())
            ->where('dvr_setting_id', $this->dvr_setting_id)
            ->where('type', DvrRuleType::Series)
            ->get(['series_title'])
            ->contains(fn (DvrRecordingRule $r) => mb_strtolower(EpgProgrammeNormalizer::cleanForSearch($r->series_title)) === $normalizedTitle);

        if ($exists) {
            Notification::make()
                ->title(__('A Series rule for ":title" already exists.', ['title' => $title]))
                ->warning()
                ->send();

            return;
        }

        DvrRecordingRule::create(array_merge([
            'user_id' => Auth::id(),
            'dvr_setting_id' => $this->dvr_setting_id,
            'type' => DvrRuleType::Series,
            'series_title' => $title,
            'enabled' => true,
        ], $options));

        $this->refreshRuleBadgeForTitle($title, 'series');

        DvrSchedulerTick::dispatch();

        Notification::make()
            ->title(__('Series rule created for ":title"', ['title' => $title]))
            ->success()
            ->send();
    }

    private function refreshRuleBadgeForTitle(string $title, string $type): void
    {
        foreach ($this->shows as $index => $show) {
            if ($show['title'] === $title) {
                if ($type === 'series') {
                    $this->shows[$index]['has_series_rule'] = true;
                    if ($this->selectedShowTitle === $title && $this->selectedShowDetail !== null) {
                        $this->selectedShowDetail['has_series_rule'] = true;
                    }
                } elseif ($type === 'once') {
                    $this->shows[$index]['has_once_rule'] = true;
                }
                break;
            }
        }
    }

    private function refreshRuleBadgeForProgramme(int $programmeId, string $type): void
    {
        // recordOnce is only reachable from the open slide-over, so selectedShowTitle is always set.
        if ($type === 'once' && $this->selectedShowTitle) {
            $this->refreshRuleBadgeForTitle($this->selectedShowTitle, 'once');
            if ($this->selectedShowDetail !== null) {
                $this->selectedShowDetail['has_once_rule'] = true;
            }
        }
    }

    private function loadPage(): void
    {
        $base = $this->buildBaseQuery();

        $this->totalShows = (clone $base)->distinct()->count('title');

        if ($this->totalShows === 0) {
            $this->shows = [];

            return;
        }

        $offset = ($this->currentPage - 1) * self::PER_PAGE;

        $titles = (clone $base)
            ->select('title')
            ->groupBy('title')
            ->orderByRaw('MIN(start_time)')
            ->offset($offset)
            ->limit(self::PER_PAGE)
            ->pluck('title');

        if ($titles->isEmpty()) {
            $this->shows = [];

            return;
        }

        $programmes = (clone $base)
            ->whereIn('title', $titles->all())
            ->orderBy('start_time')
            ->get();

        $this->shows = $this->buildCardData($programmes);
    }

    /**
     * Base query with all active filters applied — no grouping or pagination.
     * Clone before adding further constraints.
     */
    private function buildBaseQuery(): Builder
    {
        $userId = Auth::id();

        $epgIds = Epg::where('user_id', $userId)->pluck('id');

        $query = EpgProgramme::query()
            ->whereIn('epg_id', $epgIds)
            ->where('start_time', '>=', now())
            ->where('start_time', '<=', now()->addDays($this->days));

        if (! empty($this->keyword)) {
            $kw = mb_strtolower($this->keyword);
            $query->whereRaw('LOWER(title) LIKE ?', ["%{$kw}%"]);
        }

        if (! empty($this->category)) {
            $kw = mb_strtolower($this->category);
            $query->whereRaw('LOWER(category) LIKE ?', ["%{$kw}%"]);
        }

        if (! empty($this->description_keyword)) {
            $kw = mb_strtolower($this->description_keyword);
            $query->where(function ($q) use ($kw): void {
                $q->whereRaw('LOWER(description) LIKE ?', ["%{$kw}%"])
                    ->orWhereRaw('LOWER(subtitle) LIKE ?', ["%{$kw}%"]);
            });
        }

        $playlistId = $this->dvr_setting_id
            ? $this->getCachedDvrSetting()?->playlist_id
            : null;

        if ($playlistId) {
            $epgChannelIds = $this->resolveEpgChannelScope($playlistId);

            if ($epgChannelIds !== null) {
                $query->whereIn('epg_channel_id', $epgChannelIds);
            }
        }

        return $query;
    }

    /**
     * Build slim card data from a flat programmes collection (no airings embedded).
     *
     * @param  Collection<int, EpgProgramme>  $programmes
     * @return array<int, array<string, mixed>>
     */
    private function buildCardData(Collection $programmes): array
    {
        if ($programmes->isEmpty()) {
            return [];
        }

        $seriesRuleTitles = [];
        $onceProgrammeIds = [];

        if ($this->dvr_setting_id) {
            $rules = DvrRecordingRule::where('user_id', Auth::id())
                ->where('dvr_setting_id', $this->dvr_setting_id)
                ->whereIn('type', [DvrRuleType::Series, DvrRuleType::Once])
                ->get(['type', 'series_title', 'programme_id']);

            // Normalize rule titles so variants with superscript annotations
            // (ᴸᴵᵛᴱ, ᴺᵉʷ, ᴴᴰ) match the same show regardless of which
            // EPG title variant was used when the rule was created.
            $seriesRuleTitles = $rules->where('type', DvrRuleType::Series)
                ->pluck('series_title')
                ->mapWithKeys(fn (string $t) => [mb_strtolower(EpgProgrammeNormalizer::cleanForSearch($t)) => true])
                ->all();

            $onceProgrammeIds = $rules->where('type', DvrRuleType::Once)
                ->pluck('programme_id')->flip()->all();
        }

        $timezone = config('dev.timezone') ?? app(GeneralSettings::class)->app_timezone ?? 'UTC';

        $episodeLookups = [];
        foreach ($programmes as $p) {
            [$season, $episode] = $this->parseSeasonEpisode($p);
            if ($season !== null && $episode !== null) {
                $episodeLookups[] = [
                    'title' => (string) $p->title,
                    'season' => $season,
                    'episode' => $episode,
                ];
            }
        }
        $episodeIsNewMap = app(ShowMetadataService::class)->resolveEpisodeIsNew($episodeLookups);

        $shows = [];
        foreach ($programmes->groupBy('title') as $title => $airings) {
            /** @var EpgProgramme $first */
            $first = $airings->first();

            $anyNewFromTvMaze = $airings->contains(function (EpgProgramme $p) use ($episodeIsNewMap) {
                [$season, $episode] = $this->parseSeasonEpisode($p);
                if ($season === null || $episode === null) {
                    return false;
                }

                return $episodeIsNewMap[md5("{$p->title}:{$season}:{$episode}")] ?? false;
            });

            $shows[] = [
                'title' => (string) $title,
                'next_air_date_human' => $first->start_time?->timezone($timezone)->format('D M j, g:ia'),
                'flags' => [
                    'is_new' => $airings->contains('is_new', true) || $anyNewFromTvMaze,
                    'premiere' => $airings->contains('premiere', true),
                    'previously_shown' => $airings->every(fn (EpgProgramme $p) => $p->previously_shown),
                ],
                'epg_icon' => $first->icon,
                'poster_url' => null,
                'has_series_rule' => isset($seriesRuleTitles[mb_strtolower(EpgProgrammeNormalizer::cleanForSearch((string) $title))]),
                'has_once_rule' => $airings->contains(fn (EpgProgramme $p) => isset($onceProgrammeIds[$p->id])),
                'airing_count' => $airings->count(),
                'category' => $first->category,
            ];
        }

        return $shows;
    }

    /**
     * Fetch and build full show detail for the slide-over (one DB query, on demand).
     *
     * @return array<string, mixed>|null
     */
    private function buildShowDetail(string $title): ?array
    {
        $programmes = $this->buildBaseQuery()
            ->where('title', $title)
            ->orderBy('start_time')
            ->get();

        if ($programmes->isEmpty()) {
            return null;
        }

        $channelIds = $programmes->pluck('epg_channel_id')->unique()->filter()->values()->all();
        $channelNames = $this->resolveChannelNames($channelIds);
        $timezone = config('dev.timezone') ?? app(GeneralSettings::class)->app_timezone ?? 'UTC';

        $episodeLookups = [];
        foreach ($programmes as $p) {
            [$season, $episode] = $this->parseSeasonEpisode($p);
            if ($season !== null && $episode !== null) {
                $episodeLookups[] = [
                    'title' => (string) $p->title,
                    'season' => $season,
                    'episode' => $episode,
                ];
            }
        }
        $episodeIsNewMap = app(ShowMetadataService::class)->resolveEpisodeIsNew($episodeLookups);

        $airings = $programmes->map(function (EpgProgramme $p) use ($channelNames, $timezone, $episodeIsNewMap) {
            $startTime = $p->start_time?->timezone($timezone);

            [$season, $episode, $subtitle, $description] = $this->parseSeasonEpisode($p);

            $isNewFromTvMaze = $season !== null && $episode !== null
                ? ($episodeIsNewMap[md5("{$p->title}:{$season}:{$episode}")] ?? false)
                : false;

            return [
                'id' => $p->id,
                'channel_name' => $channelNames[$p->epg_channel_id] ?? $p->epg_channel_id,
                'start_time_human' => $startTime?->format('D M j, g:ia'),
                'season' => $season,
                'episode' => $episode,
                'subtitle' => $subtitle,
                'description' => $description,
                'is_new' => $p->is_new || $isNewFromTvMaze,
                'premiere' => $p->premiere,
            ];
        })->values()->all();

        // Propagate is_new across simultaneous airings (same start_time slot).
        // EPG providers tag one channel's entry with ᴺᵉʷ and another with ᴸᴵᵛᴱ
        // for what is the same broadcast — if any airing at a given time is new,
        // all airings at that time are the same new episode.
        $newSlots = array_flip(array_column(array_filter($airings, fn ($a) => $a['is_new']), 'start_time_human'));
        if (! empty($newSlots)) {
            $airings = array_map(
                fn (array $a) => $a['is_new'] ? $a : [...$a, 'is_new' => isset($newSlots[$a['start_time_human']])],
                $airings
            );
        }

        $normalizedTitle = mb_strtolower(EpgProgrammeNormalizer::cleanForSearch($title));
        $seriesRuleExists = $this->dvr_setting_id
            ? DvrRecordingRule::where('user_id', Auth::id())
                ->where('dvr_setting_id', $this->dvr_setting_id)
                ->where('type', DvrRuleType::Series)
                ->get(['series_title'])
                ->contains(fn (DvrRecordingRule $r) => mb_strtolower(EpgProgrammeNormalizer::cleanForSearch($r->series_title)) === $normalizedTitle)
            : false;

        return [
            'title' => $title,
            'flags' => [
                'is_new' => $programmes->contains('is_new', true),
                'premiere' => $programmes->contains('premiere', true),
                'previously_shown' => $programmes->every(fn (EpgProgramme $p) => $p->previously_shown),
            ],
            'has_series_rule' => $seriesRuleExists,
            'airings' => $airings,
        ];
    }

    /**
     * @return array{0: int|null, 1: int|null, 2: string|null, 3: string|null}
     */
    private function parseSeasonEpisode(EpgProgramme $p): array
    {
        $season = $p->season;
        $episode = $p->episode;
        $subtitle = $p->subtitle;
        $description = $p->description;

        if (empty($subtitle) && empty($season) && empty($episode) && ! empty($description)) {
            $lines = explode("\n", $description, 2);
            $firstLine = trim($lines[0]);
            if (preg_match('/^S(\d+)\s+E(\d+)\s+(.+)$/i', $firstLine, $matches)) {
                $season = (int) $matches[1];
                $episode = (int) $matches[2];
                $subtitle = trim($matches[3]);
                $description = isset($lines[1]) ? trim($lines[1]) : '';
            }
        }

        return [$season, $episode, $subtitle, $description];
    }

    /**
     * @param  list<string>  $channelIds
     * @return array<string, string>
     */
    private function resolveChannelNames(array $channelIds): array
    {
        if (empty($channelIds)) {
            return [];
        }

        return EpgChannel::without('epg')
            ->whereIn('channel_id', $channelIds)
            ->get(['channel_id', 'name', 'display_name', 'name_custom', 'display_name_custom'])
            ->mapWithKeys(fn (EpgChannel $c) => [
                $c->channel_id => $c->name_custom
                    ?: $c->display_name_custom
                    ?: $c->display_name
                    ?: $c->name,
            ])
            ->all();
    }

    /**
     * Resolve the set of XMLTV channel IDs that are in scope for the given playlist.
     *
     * Matches via two paths so that both EPG-channel-mapped channels and tvg-id
     * (stream_id) channels return results:
     *   1. channels.epg_channel_id → epg_channels.channel_id  (SchedulesDirect-style numeric IDs)
     *   2. channels.stream_id                                  (XMLTV tvg-id that matches directly)
     *
     * Returns null when the playlist has no EPG-linked channels at all (i.e. no
     * filtering should be applied — all programmes are fair game). Returns an empty
     * array when a channel/group filter is active but nothing matched (0 results).
     *
     * @return list<string>|null
     */
    private function resolveEpgChannelScope(int $playlistId): ?array
    {
        $includeDisabled = $this->shouldIncludeDisabledChannels();

        if ($this->channel_id) {
            $channelQuery = DB::table('channels')
                ->join('epg_channels', 'epg_channels.id', '=', 'channels.epg_channel_id')
                ->where('channels.id', $this->channel_id)
                ->whereNotNull('channels.epg_channel_id');
            if (! $includeDisabled) {
                $channelQuery->where('channels.enabled', true);
            }
            $epgId = $channelQuery->value('epg_channels.channel_id');

            return $epgId ? [$epgId] : null;
        }

        $epgMapBase = DB::table('channels')
            ->join('epg_channels', 'epg_channels.id', '=', 'channels.epg_channel_id')
            ->where('channels.playlist_id', $playlistId)
            ->whereNotNull('channels.epg_channel_id');

        if (! $includeDisabled) {
            $epgMapBase->where('channels.enabled', true);
        }

        $streamIdBase = DB::table('channels')
            ->where('channels.playlist_id', $playlistId)
            ->whereNotNull('channels.stream_id')
            ->where('channels.stream_id', '!=', '');

        if (! $includeDisabled) {
            $streamIdBase->where('channels.enabled', true);
        }

        if ($this->channel_name) {
            $channelKw = mb_strtolower($this->channel_name);
            $ids = (clone $epgMapBase)
                ->whereRaw('LOWER(channels.title) LIKE ?', ["%{$channelKw}%"])
                ->pluck('epg_channels.channel_id')
                ->merge(
                    (clone $streamIdBase)
                        ->whereRaw('LOWER(channels.title) LIKE ?', ["%{$channelKw}%"])
                        ->pluck('channels.stream_id')
                )
                ->unique()->values()->all();

            return $ids;
        }

        if ($this->group_id) {
            $ids = (clone $epgMapBase)
                ->where('channels.group_id', $this->group_id)
                ->pluck('epg_channels.channel_id')
                ->merge(
                    (clone $streamIdBase)
                        ->where('channels.group_id', $this->group_id)
                        ->pluck('channels.stream_id')
                )
                ->unique()->values()->all();

            return $ids;
        }

        $ids = (clone $epgMapBase)
            ->pluck('epg_channels.channel_id')
            ->merge($streamIdBase->pluck('channels.stream_id'))
            ->unique()->values()->all();

        return ! empty($ids) ? $ids : null;
    }
}
