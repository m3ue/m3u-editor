<?php

namespace App\Filament\Pages;

use App\Enums\DvrRuleType;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class BrowseShows extends Page
{
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

    private const PER_PAGE = 20;

    // --- Filter state ---

    public ?int $dvr_setting_id = null;

    public string $keyword = '';

    public string $category = '';

    public string $description_keyword = '';

    public ?int $group_id = null;

    public string $channel_name = '';

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

    public bool $seriesNewOnly = false;

    public string $seriesChannelName = '';

    public int $seriesPriority = 50;

    public int $seriesStartEarly = 0;

    public int $seriesEndLate = 0;

    public ?int $seriesKeepLast = null;

    // --- Computed helpers ---

    public function getTimezoneNotSetProperty(): bool
    {
        return empty(config('dev.timezone')) && empty(app(GeneralSettings::class)->app_timezone);
    }

    public function getTotalPagesProperty(): int
    {
        return (int) ceil($this->totalShows / self::PER_PAGE);
    }

    /**
     * @return array<int, string>
     */
    public function getDvrSettingOptionsProperty(): array
    {
        return DvrSetting::with('playlist')
            ->where('user_id', Auth::id())
            ->get()
            ->mapWithKeys(fn (DvrSetting $s) => [$s->id => $s->playlist?->name ?? "DVR #{$s->id}"])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function getGroupOptionsProperty(): array
    {
        if (! $this->dvr_setting_id) {
            return [];
        }

        $playlistId = $this->resolvedDvrSetting()?->playlist_id;

        if (! $playlistId) {
            return [];
        }

        return Group::where('playlist_id', $playlistId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    // --- Lifecycle ---

    public function mount(): void
    {
        $settings = DvrSetting::where('user_id', Auth::id())->get();
        if ($settings->count() === 1) {
            $this->dvr_setting_id = $settings->first()->id;
        }
    }

    public function updatedDvrSettingId(): void
    {
        $this->group_id = null;
        $this->channel_name = '';
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
        $dvrSetting = $this->resolvedDvrSetting();

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
        $this->createSeriesRule($title, [
            'new_only' => false,
            'priority' => 50,
        ]);
    }

    public function recordSeriesWithOptions(string $title): void
    {
        $channelId = null;

        if ($this->seriesChannelName && $this->dvr_setting_id) {
            $playlistId = $this->resolvedDvrSetting()?->playlist_id;
            if ($playlistId) {
                $seriesChannelKw = mb_strtolower($this->seriesChannelName);
                $channelId = Channel::where('playlist_id', $playlistId)
                    ->whereRaw('LOWER(title) LIKE ?', ["%{$seriesChannelKw}%"])
                    ->value('id');
            }
        }

        $this->createSeriesRule($title, [
            'new_only' => $this->seriesNewOnly,
            'channel_id' => $channelId,
            'priority' => $this->seriesPriority,
            'start_early_seconds' => $this->seriesStartEarly,
            'end_late_seconds' => $this->seriesEndLate,
            'keep_last' => $this->seriesKeepLast ?: null,
        ]);
    }

    // --- Internal ---

    /**
     * Find the selected DvrSetting scoped to the authenticated user.
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
        $dvrSetting = $this->resolvedDvrSetting();

        if (! $dvrSetting) {
            Notification::make()->title(__('Select a DVR Setting first.'))->warning()->send();

            return;
        }

        $exists = DvrRecordingRule::where('user_id', Auth::id())
            ->where('dvr_setting_id', $this->dvr_setting_id)
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
            'user_id' => Auth::id(),
            'dvr_setting_id' => $this->dvr_setting_id,
            'type' => DvrRuleType::Series,
            'series_title' => $title,
            'enabled' => true,
        ], $options));

        $this->refreshRuleBadgeForTitle($title, 'series');

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

        $query = EpgProgramme::query()
            ->whereHas('epg', fn (Builder $q) => $q->where('user_id', $userId))
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

        if ($this->dvr_setting_id) {
            $playlistId = $this->resolvedDvrSetting()?->playlist_id;

            if ($playlistId) {
                $epgChannelIds = $this->resolveEpgChannelScope($playlistId);

                if ($epgChannelIds !== null) {
                    $query->whereIn('epg_channel_id', $epgChannelIds);
                }
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

        $seriesRuleTitles = $this->dvr_setting_id
            ? DvrRecordingRule::where('user_id', Auth::id())
                ->where('dvr_setting_id', $this->dvr_setting_id)
                ->where('type', DvrRuleType::Series)
                ->pluck('series_title')
                ->flip()
                ->all()
            : [];

        $onceProgrammeIds = $this->dvr_setting_id
            ? DvrRecordingRule::where('user_id', Auth::id())
                ->where('dvr_setting_id', $this->dvr_setting_id)
                ->where('type', DvrRuleType::Once)
                ->pluck('programme_id')
                ->flip()
                ->all()
            : [];

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
                'next_air_date_human' => $first->start_time?->shiftTimezone('UTC')->timezone($timezone)->format('D M j, g:ia'),
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
            $startTime = $p->start_time?->shiftTimezone('UTC')->timezone($timezone);

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

        $seriesRuleExists = $this->dvr_setting_id
            ? DvrRecordingRule::where('user_id', Auth::id())
                ->where('dvr_setting_id', $this->dvr_setting_id)
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
        $epgMapBase = DB::table('channels')
            ->join('epg_channels', 'epg_channels.id', '=', 'channels.epg_channel_id')
            ->where('channels.playlist_id', $playlistId)
            ->whereNotNull('channels.epg_channel_id');

        $streamIdBase = DB::table('channels')
            ->where('channels.playlist_id', $playlistId)
            ->whereNotNull('channels.stream_id')
            ->where('channels.stream_id', '!=', '');

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
