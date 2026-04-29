<?php

namespace App\Filament\GuestPanel\Pages;

use App\Enums\DvrRuleType;
use App\Filament\GuestPanel\Pages\Concerns\HasGuestDvr;
use App\Models\Channel;
use App\Models\DvrRecordingRule;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use App\Models\Group;
use App\Services\ShowMetadataService;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

class GuestBrowseShows extends Page
{
    use HasGuestDvr;

    protected string $view = 'filament.guest-panel.pages.browse-shows';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-s-magnifying-glass';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('Browse Shows');
    }

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

    public string $selectedShowTitle = '';

    // --- Series options form state ---

    public bool $seriesNewOnly = false;

    public ?int $seriesChannelId = null;

    public int $seriesPriority = 50;

    public int $seriesStartEarly = 0;

    public int $seriesEndLate = 0;

    public ?int $seriesKeepLast = null;

    // --- Computed helpers ---

    /**
     * @return array<int, string>
     */
    public function getGroupOptionsProperty(): array
    {
        $playlistId = static::getDvrSetting()?->playlist_id;
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
     * @return array<int, string>
     */
    public function getChannelOptionsProperty(): array
    {
        $playlistId = static::getDvrSetting()?->playlist_id;
        if (! $playlistId) {
            return [];
        }

        return Channel::where('playlist_id', $playlistId)
            ->where('enabled', true)
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();
    }

    public function getSeriesHintProperty(): string
    {
        $channelOptions = $this->channelOptions;
        $channelName = ($this->seriesChannelId && isset($channelOptions[$this->seriesChannelId]))
            ? Str::limit($channelOptions[$this->seriesChannelId], 18)
            : __('any channel');

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

    public function openShowDetail(string $title): void
    {
        Log::info('GuestBrowseShows: openShowDetail called: '.$title);
        $this->selectedShowTitle = $title;
        $this->seriesNewOnly = false;
        $this->seriesPriority = 50;
        $this->seriesStartEarly = 0;
        $this->seriesEndLate = 0;
        $this->seriesKeepLast = null;
        $this->seriesChannelId = $this->resolveDefaultChannelIdForShow($title);
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
        $this->groupedShows = $this->buildGroupedShows($programmes);

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
        $dvrSetting = static::getDvrSetting();
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

        // Resolve the channel from the programme's EPG channel ID so the scheduler
        // can start the recording without relying on the EPG fallback alone.
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

        Notification::make()
            ->title(__('Once rule created for ":title"', ['title' => $programme->title]))
            ->success()
            ->send();
    }

    public function quickRecordNextAiring(string $title): void
    {
        $show = collect($this->groupedShows)->firstWhere('title', $title);

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
        $this->createSeriesRule($title, [
            'new_only' => $this->seriesNewOnly,
            'channel_id' => $this->seriesChannelId ?: null,
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
        $dvrSetting = static::getDvrSetting();
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
                } elseif ($type === 'once') {
                    $this->groupedShows[$index]['has_once_rule'] = true;
                }
                break;
            }
        }
    }

    private function refreshRuleBadgeForProgramme(int $programmeId, string $type): void
    {
        foreach ($this->groupedShows as $index => $show) {
            foreach ($show['airings'] as $airing) {
                if ($airing['id'] === $programmeId) {
                    if ($type === 'once') {
                        $this->groupedShows[$index]['has_once_rule'] = true;
                    }
                    break 2;
                }
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildGroupedShows(Collection $programmes): array
    {
        if ($programmes->isEmpty()) {
            return [];
        }

        $dvrSetting = static::getDvrSetting();

        $channelIds = $programmes->pluck('epg_channel_id')->unique()->filter()->values()->all();
        $channelNames = $this->resolveChannelNames($channelIds);

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

        $shows = [];
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
                'next_air_date_human' => $first->start_time?->timezone($timezone)->format('D M j, g:ia'),
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
        $dvrSetting = static::getDvrSetting();

        $query = EpgProgramme::query()
            ->where('start_time', '>=', now())
            ->where('start_time', '<=', now()->addDays($this->days))
            ->orderBy('start_time');

        if (! empty($this->keyword)) {
            $query->where('title', 'like', '%'.$this->keyword.'%');
        }

        if (! empty($this->category)) {
            $query->where('category', 'like', '%'.$this->category.'%');
        }

        if (! empty($this->description_keyword)) {
            $kw = $this->description_keyword;
            $query->where(function ($q) use ($kw): void {
                $q->where('description', 'like', '%'.$kw.'%')
                    ->orWhere('subtitle', 'like', '%'.$kw.'%');
            });
        }

        if ($dvrSetting?->playlist_id) {
            $epgChannelIds = $this->resolveEpgChannelScope($dvrSetting->playlist_id);

            if ($epgChannelIds !== null) {
                $query->whereIn('epg_channel_id', $epgChannelIds);
            }
        }

        return $query->limit(100)->get();
    }

    /**
     * @return list<string>|null
     */
    private function resolveEpgChannelScope(int $playlistId): ?array
    {
        if ($this->channel_id) {
            $channel = Channel::where('id', $this->channel_id)
                ->where('enabled', true)
                ->with('epgChannel')
                ->first();
            $epgId = $channel?->epgChannel?->channel_id;

            return $epgId ? [$epgId] : null;
        }

        if ($this->group_id) {
            $ids = Channel::where('group_id', $this->group_id)
                ->where('enabled', true)
                ->whereNotNull('epg_channel_id')
                ->with('epgChannel')
                ->get()
                ->map(fn (Channel $c) => $c->epgChannel?->channel_id)
                ->filter()
                ->unique()
                ->values()
                ->all();

            return ! empty($ids) ? $ids : null;
        }

        $ids = Channel::where('playlist_id', $playlistId)
            ->where('enabled', true)
            ->whereNotNull('epg_channel_id')
            ->with('epgChannel')
            ->get()
            ->map(fn (Channel $c) => $c->epgChannel?->channel_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return ! empty($ids) ? $ids : null;
    }

    /**
     * Resolve the channel that a show most likely airs on within the current DVR
     * setting's playlist. Used to pre-populate the series options form so the user
     * sees the right channel pre-selected when they open the advanced options panel.
     */
    private function resolveDefaultChannelIdForShow(string $title): ?int
    {
        $playlistId = static::getDvrSetting()?->playlist_id;
        if (! $playlistId) {
            return null;
        }

        $show = collect($this->groupedShows)->firstWhere('title', $title);
        if (! $show || empty($show['airings'])) {
            return null;
        }

        $firstProgramme = EpgProgramme::find($show['airings'][0]['id']);
        if (! $firstProgramme?->epg_channel_id) {
            return null;
        }

        $epgChannelPk = EpgChannel::where('channel_id', $firstProgramme->epg_channel_id)->value('id');
        if (! $epgChannelPk) {
            return null;
        }

        return Channel::where('playlist_id', $playlistId)
            ->where('epg_channel_id', $epgChannelPk)
            ->value('id');
    }
}
