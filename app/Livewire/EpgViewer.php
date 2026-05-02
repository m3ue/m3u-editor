<?php

namespace App\Livewire;

use App\Enums\ChannelLogoType;
use App\Enums\DvrRuleType;
use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\EpgChannels\EpgChannelResource;
use App\Jobs\DvrSchedulerTick;
use App\Models\Channel;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use App\Models\Playlist;
use App\Services\EpgCacheService;
use Carbon\Carbon;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class EpgViewer extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    public ?array $data = [];

    public $record;

    public $type;

    public $editingChannelId = null;

    public $viewOnly = false;

    public $username = null;

    public $password = null;

    public $vod = true;

    /** Whether the associated playlist has DVR enabled. */
    public bool $dvrEnabled = false;

    /** Programme data for the pending schedule action. */
    public ?array $programmeData = null;

    /** Channel database ID for the pending schedule action. */
    public ?int $schedulingChannelId = null;

    // Use static cache to prevent Livewire from clearing it
    protected static $recordCache = [];

    protected static $maxCacheSize = 20; // Limit cache size to prevent memory issues

    public function mount($record): void
    {
        $this->record = $record;
        $this->type = class_basename($this->record);

        // Determine DVR enabled state for Playlist records
        if ($this->type === 'Playlist') {
            $this->dvrEnabled = (bool) $this->record->dvrSetting?->enabled;
        }
    }

    /**
     * Clear old cache entries if cache gets too large
     */
    protected static function maintainCacheSize(): void
    {
        if (count(static::$recordCache) > static::$maxCacheSize) {
            // Remove the oldest entries (first half of cache)
            $halfSize = intval(static::$maxCacheSize / 2);
            static::$recordCache = array_slice(static::$recordCache, $halfSize, null, true);
        }
    }

    protected function getActions(): array
    {
        return [
            $this->editChannelAction(),
            $this->scheduleProgrammeAction(),
        ];
    }

    public function editChannelAction(): Action
    {
        return EditAction::make('editChannel')
            ->label('Edit Channel')
            ->record(fn () => $this->getChannelRecord())
            ->schema($this->type === 'Epg' ? EpgChannelResource::getForm() : ChannelResource::getForm(edit: true))
            ->action(function (array $data, $record) {
                if ($record) {
                    $record->update($data);

                    Notification::make()
                        ->success()
                        ->title('Channel updated')
                        ->body('The channel has been successfully updated.')
                        ->send();

                    // Update the static cache with fresh data
                    $cacheKey = "{$this->type}_{$record->id}";
                    $eager = $this->type === 'Epg' ? [] : ['epgChannel', 'failovers'];
                    $updated = $record->fresh($eager);
                    static::$recordCache[$cacheKey] = $updated;

                    // Refresh the EPG data to reflect the changes
                    $channelId = $this->type === 'Epg'
                        ? $updated->channel_id
                        : $updated->channel;
                    $displayName = $this->type === 'Epg'
                        ? ($updated->display_name ?? $updated->name ?? $channelId)
                        : ($updated->title_custom ?? $updated->title);
                    $channelData = [
                        'channel_id' => $channelId,
                        'display_name' => $displayName,
                        'database_id' => $updated->id,
                    ];

                    // Add URL for Playlist channels
                    if ($this->type !== 'Epg') {
                        $playlist = $updated->playlist;
                        $channelResults = $updated->getFloatingPlayerAttributes();
                        $url = $channelResults['url'] ?? '';
                        $channelFormat = $channelResults['format'] ?? '';

                        // Get the icon
                        $icon = '';
                        if ($updated->logo) {
                            // Logo override takes precedence
                            $icon = $updated->logo;
                        } elseif ($updated->logo_type === ChannelLogoType::Epg) {
                            $icon = $updated->epgChannel?->icon ?? '';
                        } elseif ($updated->logo_type === ChannelLogoType::Channel) {
                            $icon = $updated->logo ?? '';
                        }
                        if (empty($icon)) {
                            $icon = url('/placeholder.png');
                        }

                        // Add URL, format, icon, and display title to channel data
                        $channelData['url'] = $url;
                        $channelData['format'] = $channelFormat;
                        $channelData['icon'] = $icon;
                        $channelData['title'] = $channelResults['title'] ?? $updated->name_custom ?? $updated->name;
                        $channelData['display_title'] = $channelResults['display_title'] ?? $updated->display_title;

                        // Fetch programme data for Playlist channels if they have an EPG channel
                        if ($updated->epgChannel) {
                            // Fetch programme data for this channel
                            $programmes = $this->fetchProgrammeData($updated->epgChannel, $channelId);
                            $channelData['programmes'] = $programmes;
                        } else {
                            // If no EPG channel, set programmes to empty
                            $channelData['programmes'] = [];
                        }
                    } else {
                        // No need to updated programmes for EPG channels
                        $channelData['icon'] = $updated->icon ?? url('/placeholder.png');
                    }
                    $this->dispatch('refresh-epg-data', $channelData);
                }
                $this->editingChannelId = null;
            })
            ->slideOver()
            ->modalWidth('4xl');
    }

    /**
     * Action to schedule a one-off DVR recording for a specific programme.
     */
    public function scheduleProgrammeAction(): Action
    {
        return Action::make('scheduleProgramme')
            ->label(__('Schedule Recording'))
            ->icon('heroicon-o-video-camera')
            ->color('danger')
            ->schema([
                Select::make('rule_type')
                    ->label(__('Recording type'))
                    ->options([
                        DvrRuleType::Once->value => __('Once — record this episode only'),
                        DvrRuleType::Series->value => __('Series — record all episodes with this title'),
                    ])
                    ->default(DvrRuleType::Once->value)
                    ->required(),
                Toggle::make('new_only')
                    ->label(__('New episodes only'))
                    ->helperText(__('Only record episodes marked as new. Applies to series rules only.'))
                    ->default(false)
                    ->inline(false),
            ])
            ->action(function (array $data) {
                $this->handleScheduleProgramme($data);
            })
            ->slideOver(false)
            ->modalWidth('lg');
    }

    /**
     * Open the schedule programme modal for a specific programme.
     *
     * @param  array{title: string, start: string, stop: string, episode_num: ?string, category: ?string}  $programmeData
     */
    public function openScheduleProgramme(array $programmeData, int $channelDatabaseId): void
    {
        $this->programmeData = $programmeData;
        $this->schedulingChannelId = $channelDatabaseId;
        $this->mountAction('scheduleProgramme');
    }

    /**
     * Create a DvrRecordingRule for the pending programme.
     *
     * @param  array{rule_type: string, new_only: bool}  $data
     */
    protected function handleScheduleProgramme(array $data): void
    {
        if (empty($this->programmeData) || empty($this->schedulingChannelId)) {
            Notification::make()
                ->danger()
                ->title(__('Recording failed'))
                ->body(__('Programme data is missing. Please try again.'))
                ->send();

            return;
        }

        /** @var Playlist $playlist */
        $playlist = $this->record;
        $dvrSetting = DvrSetting::where('playlist_id', $playlist->id)->where('enabled', true)->first();

        if (! $dvrSetting) {
            Notification::make()
                ->danger()
                ->title(__('DVR not enabled'))
                ->body(__('Enable DVR for this playlist in the playlist settings before scheduling recordings.'))
                ->send();

            return;
        }

        /** @var Channel $channel */
        $channel = Channel::find($this->schedulingChannelId);

        if (! $channel) {
            Notification::make()
                ->danger()
                ->title(__('Recording failed'))
                ->body(__('Channel not found.'))
                ->send();

            return;
        }

        $ruleType = DvrRuleType::from($data['rule_type']);
        $title = $this->programmeData['title'] ?? null;
        // Carbon::parse honors the offset in the ISO string. We must convert to
        // app.timezone wall-clock before persisting because the `datetime` cast
        // on EpgProgramme stores raw wall-clock (no tz conversion on write) and
        // reads it back as `app.timezone` — a tz mismatch would cause hour drift.
        $appTz = config('app.timezone');
        $startTime = isset($this->programmeData['start'])
            ? Carbon::parse($this->programmeData['start'])->tz($appTz)
            : null;
        $endTime = isset($this->programmeData['stop'])
            ? Carbon::parse($this->programmeData['stop'])->tz($appTz)
            : null;

        // For 'once' rules, find or create the EpgProgramme record so the scheduler can match it
        $programmeId = null;
        if ($ruleType === DvrRuleType::Once && $startTime && $endTime && $title) {
            $epgProgramme = EpgProgramme::firstOrCreate(
                [
                    'epg_channel_id' => $channel->epgChannel?->channel_id ?? '',
                    'start_time' => $startTime,
                ],
                [
                    'epg_id' => $channel->epgChannel?->epg_id ?? 0,
                    'title' => $title,
                    'end_time' => $endTime,
                    'subtitle' => $this->programmeData['subtitle'] ?? null,
                    'description' => $this->programmeData['desc'] ?? null,
                    'category' => $this->programmeData['category'] ?? null,
                    'episode_num' => $this->programmeData['episode_num'] ?? null,
                    'is_new' => $this->programmeData['new'] ?? false,
                ]
            );
            $programmeId = $epgProgramme->id;
        }

        try {
            // Duplicate-rule guard — match BrowseShows behavior to prevent rapid double-clicks
            // creating multiple identical rules.
            $duplicateQuery = DvrRecordingRule::where('dvr_setting_id', $dvrSetting->id)
                ->where('type', $ruleType);

            if ($ruleType === DvrRuleType::Once) {
                $duplicateQuery->where('programme_id', $programmeId);
            } else {
                $duplicateQuery->where('series_title', $title)
                    ->where('channel_id', $channel->id);
            }

            if ($duplicateQuery->exists()) {
                Notification::make()
                    ->warning()
                    ->title(__('Already scheduled'))
                    ->body(__('A :type rule for ":title" already exists.', [
                        'type' => $ruleType === DvrRuleType::Series ? 'series' : 'once',
                        'title' => $title,
                    ]))
                    ->send();

                return;
            }

            DvrRecordingRule::create([
                'user_id' => $dvrSetting->user_id,
                'dvr_setting_id' => $dvrSetting->id,
                'type' => $ruleType,
                'programme_id' => $programmeId,
                'series_title' => $ruleType === DvrRuleType::Series ? $title : null,
                'channel_id' => $channel->id,
                'epg_channel_id' => $channel->epgChannel?->id,
                'new_only' => $data['new_only'] ?? false,
                'priority' => 50,
                'enabled' => true,
            ]);

            // Dispatch immediate scheduler tick so the recording materialises in the
            // dvr-recordings list within seconds instead of waiting up to 60s for the
            // next cron-driven tick. Padding (start_early_seconds / end_late_seconds)
            // is unaffected — the scheduler still honors per-rule and DvrSetting defaults.
            DvrSchedulerTick::dispatch();

            $typeLabel = $ruleType === DvrRuleType::Series
                ? __('series rule created')
                : __('recording scheduled');

            Notification::make()
                ->success()
                ->title(ucfirst($typeLabel))
                ->body(__(':title has been :action.', ['title' => $title, 'action' => $typeLabel]))
                ->send();
        } catch (Exception $e) {
            Log::error('Failed to create DVR recording rule', [
                'error' => $e->getMessage(),
                'programme' => $this->programmeData,
            ]);

            Notification::make()
                ->danger()
                ->title(__('Recording failed'))
                ->body(__('An error occurred while scheduling the recording.'))
                ->send();
        } finally {
            $this->programmeData = null;
            $this->schedulingChannelId = null;
        }
    }

    protected function getChannelRecord()
    {
        $cacheKey = "{$this->type}_{$this->editingChannelId}";

        // Use static cache if available
        if (isset(static::$recordCache[$cacheKey])) {
            return static::$recordCache[$cacheKey];
        }
        if (! $this->editingChannelId) {
            return null;
        }

        $channel = $this->type === 'Epg'
            ? EpgChannel::find($this->editingChannelId)
            : Channel::with(['epgChannel', 'failovers'])->find($this->editingChannelId);

        // Cache the record in static cache
        if ($channel) {
            static::$recordCache[$cacheKey] = $channel;
            static::maintainCacheSize();
        }

        return $channel;
    }

    /**
     * Fetch programme data for a channel
     */
    protected function fetchProgrammeData($epgChannel, $channelId)
    {
        try {
            // Get today's date for programme lookup
            $today = now()->format('Y-m-d');

            // Get the EPG that this channel belongs to - ensure it's fully loaded
            $epg = $epgChannel->epg;

            // If EPG is not fully loaded, reload it with all attributes
            if (! $epg || ! $epg->uuid) {
                $epg = Epg::find($epgChannel->epg_id);
            }

            if (! $epg) {
                Log::debug('No EPG found for EPG channel', [
                    'epg_channel_id' => $epgChannel->id,
                    'epg_channel_epg_id' => $epgChannel->epg_id,
                ]);

                return [];
            }

            // Use the EpgCacheService to get programme data
            $cacheService = app(EpgCacheService::class);

            // Get programmes for this specific channel
            $programmes = $cacheService->getCachedProgrammes($epg, $today, [$epgChannel->channel_id]);

            // Return programmes for this channel, or empty array if none found
            return $programmes[$epgChannel->channel_id] ?? [];
        } catch (Exception $e) {
            Log::error('Failed to fetch programme data', [
                'channel_id' => $channelId,
                'epg_channel_id' => $epgChannel->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function openChannelEdit($channelId)
    {
        $this->editingChannelId = $channelId;
        $this->mountAction('editChannel');
    }

    public function render()
    {
        $route = $this->type === 'Epg'
            ? route('api.epg.data', ['uuid' => $this->record?->uuid])
            : route('api.epg.playlist.data', ['uuid' => $this->record?->uuid]);

        $groupsApiUrl = $this->type !== 'Epg'
            ? route('api.epg.playlist.groups', ['uuid' => $this->record?->uuid])
            : null;

        return view('livewire.epg-viewer', [
            'route' => $route,
            'groupsApiUrl' => $groupsApiUrl,
            'vod' => $this->vod,
            'username' => $this->username,
            'password' => $this->password,
        ]);
    }
}
