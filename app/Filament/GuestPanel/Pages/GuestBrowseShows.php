<?php

namespace App\Filament\GuestPanel\Pages;

use App\Enums\DvrRuleType;
use App\Enums\DvrSeriesMode;
use App\Filament\GuestPanel\Pages\Concerns\HasGuestDvr;
use App\Jobs\DvrSchedulerTick;
use App\Models\Channel;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use App\Models\Group;
use App\Services\ShowMetadataService;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

class GuestBrowseShows extends Page
{
    use HasGuestDvr;

    protected string $view = 'filament.guest-panel.pages.browse-shows';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-s-magnifying-glass';

    public static function getNavigationLabel(): string
    {
        return __('Browse Shows');
    }

    protected static ?int $navigationSort = 6;

    public function getTitle(): string|Htmlable
    {
        return '';
    }

    protected static ?string $slug = 'browse-shows';

    public static function canAccess(): bool
    {
        return static::guestCanAccessDvr();
    }

    public static function getUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        $tenant = null,
        bool $shouldGuessMissingParameters = false,
        ?string $configuration = null
    ): string {
        $parameters['uuid'] = static::getCurrentUuid();

        return route(static::getRouteName($panel), $parameters, $isAbsolute);
    }

    private const PER_PAGE = 20;

    // --- Filter state ---

    public string $keyword = '';

    public string $category = '';

    public string $description_keyword = '';

    public ?int $group_id = null;

    public ?int $channel_id = null;

    public int $days = 14;

    // --- Result state ---

    public bool $searched = false;

    public bool $postersLoaded = false;

    /** @var array<int, array<string, mixed>> */
    public array $groupedShows = [];

    public int $totalShows = 0;

    public int $currentPage = 1;

    // --- Slide-over ---

    public string $selectedShowTitle = '';

    /** @var array<string, mixed>|null */
    public ?array $selectedShowDetail = null;

    // --- Series options form state ---

    public int $seriesNewOnly = 0;

    /** 0 = "From Original Source" (sentinel), null/negative = "Any channel", int = specific channel */
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

    public function getTotalPagesProperty(): int
    {
        return (int) ceil($this->totalShows / self::PER_PAGE);
    }

    /**
     * @return array<int, string>
     */
    public function getGroupOptionsProperty(): array
    {
        $playlistId = $this->getCachedDvrSetting()?->playlist_id;
        if (! $playlistId) {
            return [];
        }

        return Group::where('playlist_id', $playlistId)
            ->where('enabled', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Lazily populated on first dropdown open. Not computed eagerly to avoid
     * serialising thousands of channel names into the initial page HTML.
     *
     * @var array<int, string>
     */
    public array $channelOptions = [];

    public function loadChannelOptions(): void
    {
        if (! empty($this->channelOptions)) {
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

        $this->channelOptions = $channels->get()
            ->mapWithKeys(function (Channel $c) {
                return [$c->id => $c->title_custom ?: $c->title ?: $c->name_custom ?: $c->name];
            })
            ->sortBy(fn (string $label) => mb_strtolower($label))
            ->all();
    }

    public function getSeriesHintProperty(): string
    {
        $channelOptions = $this->channelOptions;

        $channelName = match (true) {
            $this->seriesChannelId === 0 => $this->sourceChannelId && isset($channelOptions[$this->sourceChannelId])
                ? Str::limit($channelOptions[$this->sourceChannelId], 18)
                : __('original source'),
            $this->seriesChannelId > 0 && isset($channelOptions[$this->seriesChannelId]) => Str::limit($channelOptions[$this->seriesChannelId], 18),
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

    // --- Slide-over ---

    public function openShowDetail(string $title): void
    {
        $this->selectedShowTitle = $title;
        $this->seriesNewOnly = 0;
        $this->seriesPriority = 50;
        $this->seriesStartEarly = 0;
        $this->seriesEndLate = 0;
        $this->seriesKeepLast = null;
        $this->sourceChannelId = $this->resolveSourceChannelId($title);
        $this->seriesChannelId = 0;
        $this->selectedShowDetail = $this->buildShowDetail($title);
        $this->loadChannelOptions();
    }

    public function closeShowDetail(): void
    {
        $this->selectedShowTitle = '';
        $this->selectedShowDetail = null;
    }

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
        if (empty($this->groupedShows)) {
            $this->postersLoaded = true;

            return;
        }

        $titles = array_column($this->groupedShows, 'title');
        $posterUrls = app(ShowMetadataService::class)->resolvePosters($titles);

        foreach ($this->groupedShows as $index => $show) {
            $this->groupedShows[$index]['poster_url'] = $posterUrls[$show['title']] ?? null;
        }

        $this->postersLoaded = true;
    }

    // --- Recording actions ---

    public function recordOnce(int $programmeId): void
    {
        $dvrSetting = $this->getCachedDvrSetting();
        $auth = static::getCurrentPlaylistAuth();

        if (! $dvrSetting) {
            Notification::make()->title(__('DVR not available for this playlist.'))->warning()->send();

            return;
        }

        $programme = EpgProgramme::find($programmeId);

        if (! $programme) {
            Notification::make()->title(__('Programme not found.'))->danger()->send();

            return;
        }

        $exists = DvrRecordingRule::where('dvr_setting_id', $dvrSetting->id)
            ->where('type', DvrRuleType::Once)
            ->where('programme_id', $programmeId)
            ->exists();

        if ($exists) {
            Notification::make()->title(__('A Once rule for this programme already exists.'))->warning()->send();

            return;
        }

        $channel = null;
        if ($programme->epg_channel_id) {
            $epgChannelPk = EpgChannel::where('channel_id', $programme->epg_channel_id)->value('id');
            if ($epgChannelPk) {
                $channel = Channel::where('playlist_id', $dvrSetting->playlist_id)
                    ->where('epg_channel_id', $epgChannelPk)
                    ->first();
            }
        }

        if (! $channel) {
            Notification::make()
                ->title(__('No matching channel found for ":title". The recording may not start — check your channel EPG mapping.', ['title' => $programme->title]))
                ->warning()
                ->send();
        }

        DvrRecordingRule::create([
            'user_id' => $dvrSetting->user_id,
            'playlist_auth_id' => $auth?->id,
            'dvr_setting_id' => $dvrSetting->id,
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
        if ($this->selectedShowTitle === $title && ! empty($this->selectedShowDetail['airings'])) {
            $this->recordOnce($this->selectedShowDetail['airings'][0]['id']);

            return;
        }

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

    private function getCachedDvrSetting(): ?DvrSetting
    {
        if (! $this->dvrSettingResolved) {
            $this->cachedDvrSetting = static::getDvrSetting();
            $this->dvrSettingResolved = true;
        }

        return $this->cachedDvrSetting;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function createSeriesRule(string $title, array $options): void
    {
        $dvrSetting = $this->getCachedDvrSetting();
        $auth = static::getCurrentPlaylistAuth();

        if (! $dvrSetting) {
            Notification::make()->title(__('DVR not available for this playlist.'))->warning()->send();

            return;
        }

        $exists = DvrRecordingRule::where('dvr_setting_id', $dvrSetting->id)
            ->where('type', DvrRuleType::Series)
            ->where('series_title', $title)
            ->exists();

        if ($exists) {
            Notification::make()
                ->title(__('A Series rule for ":title" already exists.', ['title' => $title]))
                ->warning()
                ->send();

            return;
        }

        DvrRecordingRule::create(array_merge([
            'user_id' => $dvrSetting->user_id,
            'playlist_auth_id' => $auth?->id,
            'dvr_setting_id' => $dvrSetting->id,
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
        foreach ($this->groupedShows as $index => $show) {
            if ($show['title'] === $title) {
                if ($type === 'series') {
                    $this->groupedShows[$index]['has_series_rule'] = true;
                    if ($this->selectedShowTitle === $title && $this->selectedShowDetail !== null) {
                        $this->selectedShowDetail['has_series_rule'] = true;
                    }
                } elseif ($type === 'once') {
                    $this->groupedShows[$index]['has_once_rule'] = true;
                }
                break;
            }
        }
    }

    private function refreshRuleBadgeForProgramme(int $programmeId, string $type): void
    {
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
            $this->groupedShows = [];

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
            $this->groupedShows = [];

            return;
        }

        $programmes = (clone $base)
            ->whereIn('title', $titles->all())
            ->orderBy('start_time')
            ->get();

        $this->groupedShows = $this->buildGroupedShows($programmes);
    }

    /**
     * Base query with all active filters applied — no grouping or pagination.
     * Clone before adding further constraints.
     */
    private function buildBaseQuery(): Builder
    {
        $dvrSetting = $this->getCachedDvrSetting();

        $query = EpgProgramme::query()
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

        if ($dvrSetting?->playlist_id) {
            $epgChannelIds = $this->resolveEpgChannelScope($dvrSetting->playlist_id);

            if ($epgChannelIds !== null) {
                $query->whereIn('epg_channel_id', $epgChannelIds);
            }
        }

        return $query;
    }

    /**
     * Build slim card data from a flat programmes collection (airings NOT embedded).
     *
     * @param  Collection<int, EpgProgramme>  $programmes
     * @return array<int, array<string, mixed>>
     */
    private function buildGroupedShows(Collection $programmes): array
    {
        if ($programmes->isEmpty()) {
            return [];
        }

        $dvrSetting = $this->getCachedDvrSetting();

        $seriesRuleTitles = $dvrSetting
            ? DvrRecordingRule::where('dvr_setting_id', $dvrSetting->id)
                ->where('type', DvrRuleType::Series)
                ->pluck('series_title')
                ->flip()
                ->all()
            : [];

        $onceProgrammeIds = $dvrSetting
            ? DvrRecordingRule::where('dvr_setting_id', $dvrSetting->id)
                ->where('type', DvrRuleType::Once)
                ->pluck('programme_id')
                ->flip()
                ->all()
            : [];

        $timezone = app(GeneralSettings::class)->app_timezone ?? 'UTC';

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
                'has_series_rule' => isset($seriesRuleTitles[(string) $title]),
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
        $timezone = app(GeneralSettings::class)->app_timezone ?? 'UTC';

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
                'start_time' => $startTime?->format('Y-m-d H:i'),
                'start_time_human' => $startTime?->format('D M j, g:ia'),
                'end_time' => $p->end_time?->format('Y-m-d H:i'),
                'season' => $season,
                'episode' => $episode,
                'subtitle' => $subtitle,
                'description' => $description,
                'is_new' => $p->is_new || $isNewFromTvMaze,
                'premiere' => $p->premiere,
            ];
        })->values()->all();

        $dvrSetting = $this->getCachedDvrSetting();
        $seriesRuleExists = $dvrSetting
            ? DvrRecordingRule::where('dvr_setting_id', $dvrSetting->id)
                ->where('type', DvrRuleType::Series)
                ->where('series_title', $title)
                ->exists()
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

    private function shouldIncludeDisabledChannels(): bool
    {
        return $this->getCachedDvrSetting()?->include_disabled_channels ?? false;
    }

    /**
     * Resolve the set of XMLTV channel IDs in scope for the given playlist.
     *
     * Uses SQL joins (same as the authenticated BrowseShows) to avoid loading
     * every Channel model into PHP memory.
     *
     * @return list<string>|null
     */
    private function resolveEpgChannelScope(int $playlistId): ?array
    {
        $includeDisabled = $this->shouldIncludeDisabledChannels();

        if ($this->channel_id) {
            $channelQuery = Channel::where('id', $this->channel_id)->with('epgChannel');
            if (! $includeDisabled) {
                $channelQuery->where('enabled', true);
            }
            $channel = $channelQuery->first();
            $epgId = $channel?->epgChannel?->channel_id;

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

            return ! empty($ids) ? $ids : null;
        }

        $ids = (clone $epgMapBase)
            ->pluck('epg_channels.channel_id')
            ->merge($streamIdBase->pluck('channels.stream_id'))
            ->unique()->values()->all();

        return ! empty($ids) ? $ids : null;
    }

    /**
     * Resolve the channel (within the DVR setting's playlist) that a show most likely airs on.
     */
    private function resolveSourceChannelId(string $title): ?int
    {
        $playlistId = $this->getCachedDvrSetting()?->playlist_id;
        if (! $playlistId) {
            return null;
        }

        $programme = $this->buildBaseQuery()
            ->where('title', $title)
            ->orderBy('start_time')
            ->first(['epg_channel_id']);

        if (! $programme?->epg_channel_id) {
            return null;
        }

        $epgChannelPk = EpgChannel::where('channel_id', $programme->epg_channel_id)->value('id');
        if (! $epgChannelPk) {
            return null;
        }

        return Channel::where('playlist_id', $playlistId)
            ->where('epg_channel_id', $epgChannelPk)
            ->value('id');
    }
}
