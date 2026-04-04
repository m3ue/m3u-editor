<?php

namespace App\Livewire;

use App\Enums\ChannelLogoType;
use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\EpgChannels\EpgChannelResource;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Services\EpgCacheService;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\EditAction;
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

    // Use static cache to prevent Livewire from clearing it
    protected static $recordCache = [];

    protected static $maxCacheSize = 20; // Limit cache size to prevent memory issues

    public function mount($record): void
    {
        $this->record = $record;
        $this->type = class_basename($this->record);
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

                        $playerAttributes = $record->getFloatingPlayerAttributes($this->username, $this->password);

                        // Add URL, format, and icon to channel data
                        $channelData['url'] = $url;
                        $channelData['format'] = $channelFormat;
                        $channelData['cast_url'] = $playerAttributes['cast_url'] ?? null;
                        $channelData['cast_format'] = $playerAttributes['cast_format'] ?? null;
                        $channelData['cast_unavailable_reason'] = $playerAttributes['cast_unavailable_reason'] ?? null;
                        $channelData['content_type'] = $playerAttributes['content_type'] ?? 'live';
                        $channelData['title'] = $playerAttributes['title'] ?? ($updated->name_custom ?? $updated->name);
                        $channelData['display_title'] = $playerAttributes['display_title'] ?? ($updated->title_custom ?? $updated->title ?? $updated->name_custom ?? $updated->name);
                        $channelData['icon'] = $icon;

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

        return view('livewire.epg-viewer', [
            'route' => $route,
            'vod' => $this->vod,
            'username' => $this->username,
            'password' => $this->password,
        ]);
    }
}
