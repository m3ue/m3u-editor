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
use Illuminate\Support\Facades\Log;
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

    public ?int $channel_id = null;

    public int $days = 14;

    // --- Result state ---

    public bool $searched = false;

    public bool $postersLoaded = false;

    /** @var array<int, array<string, mixed>> */
    public array $groupedShows = [];

    public string $selectedShowTitle = '';

    /**
     * Whether the application timezone has been explicitly set by the user.
     */
    public function getTimezoneNotSetProperty(): bool
    {
        return empty(app(GeneralSettings::class)->app_timezone);
    }

    // --- Series options form state ---

    public bool $seriesNewOnly = false;

    public ?int $seriesChannelId = null;

    public int $seriesPriority = 50;

    public int $seriesStartEarly = 0;

    public int $seriesEndLate = 0;

    public ?int $seriesKeepLast = null;

    // --- Lifecycle ---

    public function mount(): void
    {
        $settings = DvrSetting::where('user_id', Auth::id())->get();
        if ($settings->count() === 1) {
            $this->dvr_setting_id = $settings->first()->id;
        }
    }

    // --- Computed helpers ---

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

    /**
     * @return array<int, string>
     */
    public function getChannelOptionsProperty(): array
    {
        if (! $this->dvr_setting_id) {
            return [];
        }

        $playlistId = DvrSetting::find($this->dvr_setting_id)?->playlist_id;

        if (! $playlistId) {
            return [];
        }

        return Channel::where('playlist_id', $playlistId)
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();
    }

    // --- Slide-over actions ---

    public function openShowDetail(string $title): void
    {
        Log::info('openShowDetail called: '.$title);
        $this->selectedShowTitle = $title;
    }

    public function testMethod(): void
    {
        Log::info('testMethod called');
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
        $timezone = app(GeneralSettings::class)->app_timezone ?? 'UTC';

        foreach ($programmes->groupBy('title') as $title => $airings) {
            /** @var EpgProgramme $first */
            $first = $airings->first();
            $hasOnceRule = $airings->contains(fn (EpgProgramme $p) => isset($onceProgrammeIds[$p->id]));

            $shows[] = [
                'title' => (string) $title,
                'next_air_date' => $first->start_time?->format('Y-m-d H:i'),
                'next_air_date_human' => $first->start_time?->shiftTimezone('UTC')->timezone($timezone)->format('D M j, g:ia'),
                'flags' => [
                    'is_new' => $airings->contains('is_new', true),
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
                'airings' => $airings->map(function (EpgProgramme $p) use ($channelNames, $timezone) {
                    $startTime = $p->start_time?->shiftTimezone('UTC')->timezone($timezone);

                    // Some EPG providers embed "SXX EXX Title\nSynopsis" in description
                    // rather than using the proper season/episode/subtitle fields.
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
'is_new' => $p->is_new || ($p->season !== null && $p->episode === 1 && ! $p->premiere),
                        'premiere' => $p->premiere,
                    ];
                })->values()->all(),
            ];
        }

        return $shows;
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
        if ($this->channel_id) {
            $channel = Channel::with('epgChannel')->find($this->channel_id);
            $epgId = $channel?->epgChannel?->channel_id;

            return $epgId ? [$epgId] : null;
        }

        if ($this->group_id) {
            $ids = Channel::where('group_id', $this->group_id)
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
}
