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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
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

    /** Short cache key so large show data never lives in the Livewire snapshot. */
    public string $showsCacheKey = '';

    public string $selectedShowTitle = '';

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

        $playlistId = DvrSetting::find($this->dvr_setting_id)?->playlist_id;

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
        $this->showsCacheKey = 'browse-shows-'.Auth::id().'-'.Str::random(16);

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

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'groupedShows' => $this->getGroupedShows(),
        ];
    }

    // --- Cache helpers ---

    /** @return array<int, array<string, mixed>> */
    protected function getGroupedShows(): array
    {
        return cache()->get($this->showsCacheKey, []);
    }

    /** @param array<int, array<string, mixed>> $shows */
    protected function setGroupedShows(array $shows): void
    {
        cache()->put($this->showsCacheKey, $shows, now()->addHour());
    }

    // --- Slide-over ---

    public function openShowDetail(string $title): void
    {
        $this->selectedShowTitle = $title;
    }

    public function closeShowDetail(): void
    {
        $this->selectedShowTitle = '';
    }

    // --- Search ---

    public function search(): void
    {
        $this->searched = true;
        $this->postersLoaded = false;

        $programmes = $this->runSearch();
        $this->setGroupedShows($this->buildGroupedShows($programmes));

        $this->dispatch('start-poster-load');
    }

    #[On('start-poster-load')]
    public function loadPosters(): void
    {
        $shows = $this->getGroupedShows();

        if (empty($shows)) {
            $this->postersLoaded = true;

            return;
        }

        $titles = array_column($shows, 'title');
        $posterUrls = app(ShowMetadataService::class)->resolvePosters($titles);

        foreach ($shows as $index => $show) {
            $shows[$index]['poster_url'] = $posterUrls[$show['title']] ?? null;
        }

        $this->setGroupedShows($shows);
        $this->postersLoaded = true;
    }

    // --- Recording actions ---

    public function recordOnce(int $programmeId): void
    {
        if (! $this->dvr_setting_id) {
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

        DvrRecordingRule::create([
            'user_id' => Auth::id(),
            'dvr_setting_id' => $this->dvr_setting_id,
            'type' => DvrRuleType::Once,
            'programme_id' => $programmeId,
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
        $show = collect($this->getGroupedShows())->firstWhere('title', $title);

        if (! $show || empty($show['airings'])) {
            Notification::make()
                ->title(__('No upcoming airings found for ":title"', ['title' => $title]))
                ->warning()
                ->send();

            return;
        }

        $this->recordOnce($show['airings'][0]['id']);
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
            $playlistId = DvrSetting::find($this->dvr_setting_id)?->playlist_id;
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
     * @param  array<string, mixed>  $options
     */
    private function createSeriesRule(string $title, array $options): void
    {
        if (! $this->dvr_setting_id) {
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
        $shows = $this->getGroupedShows();

        foreach ($shows as $index => $show) {
            if ($show['title'] === $title) {
                if ($type === 'series') {
                    $shows[$index]['has_series_rule'] = true;
                } elseif ($type === 'once') {
                    $shows[$index]['has_once_rule'] = true;
                }
                break;
            }
        }

        $this->setGroupedShows($shows);
    }

    private function refreshRuleBadgeForProgramme(int $programmeId, string $type): void
    {
        $shows = $this->getGroupedShows();

        foreach ($shows as $index => $show) {
            foreach ($show['airings'] as $airing) {
                if ($airing['id'] === $programmeId) {
                    if ($type === 'once') {
                        $shows[$index]['has_once_rule'] = true;
                    }
                    break 2;
                }
            }
        }

        $this->setGroupedShows($shows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildGroupedShows(Collection $programmes): array
    {
        if ($programmes->isEmpty()) {
            return [];
        }

        $channelIds = $programmes->pluck('epg_channel_id')->unique()->filter()->values()->all();
        $channelNames = $this->resolveChannelNames($channelIds);

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

        $shows = [];
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

        foreach ($programmes->groupBy('title') as $title => $airings) {
            /** @var EpgProgramme $first */
            $first = $airings->first();
            $hasOnceRule = $airings->contains(fn (EpgProgramme $p) => isset($onceProgrammeIds[$p->id]));

            $anyNewFromTvMaze = $airings->contains(function (EpgProgramme $p) use ($episodeIsNewMap) {
                [$season, $episode] = $this->parseSeasonEpisode($p);
                if ($season === null || $episode === null) {
                    return false;
                }

                return $episodeIsNewMap[md5("{$p->title}:{$season}:{$episode}")] ?? false;
            });

            $shows[] = [
                'title' => (string) $title,
                'next_air_date' => $first->start_time?->format('Y-m-d H:i'),
                'next_air_date_human' => $first->start_time?->shiftTimezone('UTC')->timezone($timezone)->format('D M j, g:ia'),
                'flags' => [
                    'is_new' => $airings->contains('is_new', true) || $anyNewFromTvMaze,
                    'premiere' => $airings->contains('premiere', true),
                    'previously_shown' => $airings->every(fn (EpgProgramme $p) => $p->previously_shown),
                ],
                'epg_icon' => $first->icon,
                'poster_url' => null,
                'has_series_rule' => isset($seriesRuleTitles[(string) $title]),
                'has_once_rule' => $hasOnceRule,
                'airing_count' => $airings->count(),
                'category' => $first->category,
                'description' => $first->description,
                'airings' => $airings->map(function (EpgProgramme $p) use ($channelNames, $timezone, $episodeIsNewMap) {
                    $startTime = $p->start_time?->shiftTimezone('UTC')->timezone($timezone);

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
                })->values()->all(),
            ];
        }

        return $shows;
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
     * @return Collection<int, EpgProgramme>
     */
    private function runSearch(): Collection
    {
        $query = EpgProgramme::query()
            ->where('start_time', '>=', now())
            ->where('start_time', '<=', now()->addDays($this->days))
            ->orderBy('start_time');

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
            $playlistId = DvrSetting::find($this->dvr_setting_id)?->playlist_id;

            if ($playlistId) {
                $epgChannelIds = $this->resolveEpgChannelScope($playlistId);

                if ($epgChannelIds !== null) {
                    $query->whereIn('epg_channel_id', $epgChannelIds);
                }
            }
        }

        return $query->limit(100)->get();
    }

    /**
     * @return list<string>|null
     */
    private function resolveEpgChannelScope(int $playlistId): ?array
    {
        $base = Channel::whereNotNull('channels.epg_channel_id')
            ->join('epg_channels', 'epg_channels.id', '=', 'channels.epg_channel_id');

        if ($this->channel_name) {
            $channelKw = mb_strtolower($this->channel_name);
            $ids = (clone $base)
                ->where('channels.playlist_id', $playlistId)
                ->whereRaw('LOWER(channels.title) LIKE ?', ["%{$channelKw}%"])
                ->pluck('epg_channels.channel_id')
                ->unique()
                ->values()
                ->all();

            return ! empty($ids) ? $ids : null;
        }

        if ($this->group_id) {
            $ids = (clone $base)
                ->where('channels.group_id', $this->group_id)
                ->pluck('epg_channels.channel_id')
                ->unique()
                ->values()
                ->all();

            return ! empty($ids) ? $ids : null;
        }

        $ids = (clone $base)
            ->where('channels.playlist_id', $playlistId)
            ->pluck('epg_channels.channel_id')
            ->unique()
            ->values()
            ->all();

        return ! empty($ids) ? $ids : null;
    }
}
