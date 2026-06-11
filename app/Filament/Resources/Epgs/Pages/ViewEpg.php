<?php

namespace App\Filament\Resources\Epgs\Pages;

use App\Enums\Status;
use App\Facades\PlaylistFacade;
use App\Filament\Resources\Epgs\EpgResource;
use App\Filament\Resources\Epgs\Widgets\ImportProgress;
use App\Jobs\GenerateEpgCache;
use App\Jobs\ProcessEpgImport;
use App\Livewire\EpgViewer;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewEpg extends ViewRecord
{
    protected static string $resource = EpgResource::class;

    public function getVisibleHeaderWidgets(): array
    {
        return [
            ImportProgress::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(__('Process'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () {
                    $record = $this->getRecord();
                    $record->update([
                        'status' => Status::Processing,
                        'progress' => 0,
                        'sd_progress' => 0,
                        'cache_progress' => 0,
                    ]);
                    app('Illuminate\Contracts\Bus\Dispatcher')
                        ->dispatch(new ProcessEpgImport($record, force: true));
                })->after(function () {
                    Notification::make()
                        ->success()
                        ->title(__('EPG is processing'))
                        ->body(__('EPG is being processed in the background. The view will update when complete.'))
                        ->duration(5000)
                        ->send();
                })
                ->disabled(fn () => $this->getRecord()->status === Status::Processing)
                ->requiresConfirmation()
                ->modalDescription(__('Process EPG now? This will reload the EPG data from the source.'))
                ->modalSubmitActionLabel(__('Yes, refresh now')),

            ActionGroup::make([
                Action::make('cache')
                    ->label(__('Generate Cache'))
                    ->icon('heroicon-o-arrows-pointing-in')
                    ->color('gray')
                    ->action(function () {
                        $record = $this->getRecord();
                        $record->update([
                            'status' => Status::Processing,
                            'cache_progress' => 0,
                        ]);
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new GenerateEpgCache($record->uuid, notify: true));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('EPG Cache is being generated'))
                            ->body(__('EPG Cache is being generated in the background. You will be notified when complete.'))
                            ->duration(5000)
                            ->send();
                    })
                    ->disabled(fn () => $this->getRecord()->status === Status::Processing)
                    ->requiresConfirmation()
                    ->modalDescription(__('Generate EPG Cache now? This will create a cache for the EPG data.'))
                    ->modalSubmitActionLabel(__('Yes, generate cache now')),

                Action::make('download')
                    ->label(__('Download EPG'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn () => route('epg.file', ['uuid' => $this->getRecord()->uuid]))
                    ->openUrlInNewTab(),

                Action::make('download_mediaflow_epg')
                    ->label(__('MediaFlow Proxy EPG'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn () => PlaylistFacade::mediaFlowProxyEnabled())
                    ->url(function () {
                        $settings = PlaylistFacade::getMediaFlowSettings();
                        $proxyUrl = PlaylistFacade::getMediaFlowProxyServerUrl();

                        return $proxyUrl.'/proxy/epg?d='.urlencode(route('epg.file', ['uuid' => $this->getRecord()->uuid])).'&api_password='.$settings['mediaflow_proxy_password'];
                    })
                    ->openUrlInNewTab(),
            ])->button()->color('gray'),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        $record = $this->getRecord();
        // if ($record->channel_count === 0) {
        $record->loadCount('channels');
        $record->channel_count = $record->channels_count;
        // }
        $record->programme_count = $record->programme_count ?: ($record->cache_meta['total_programmes'] ?? 0);

        return $schema
            ->schema([
                Section::make(__('EPG Information'))
                    ->collapsible(true)
                    ->compact()
                    ->persistCollapsed(true)
                    ->schema([
                        Grid::make()
                            ->columns(2)
                            ->columnSpan(2)
                            ->schema([
                                Grid::make()
                                    ->columnSpan(1)
                                    ->columns(1)
                                    ->schema([
                                        TextEntry::make('name')
                                            ->label(__('Name')),
                                        TextEntry::make('status')
                                            ->badge()
                                            ->color(fn ($state) => $state?->getColor()),
                                        TextEntry::make('synced')
                                            ->label(__('Last Synced'))
                                            ->since()
                                            ->placeholder(__('Never')),
                                    ]),
                                Grid::make()
                                    ->columnSpan(1)
                                    ->columns(1)
                                    ->schema([
                                        IconEntry::make('is_cached')
                                            ->label(__('Cached'))
                                            ->icon(fn (string $state): string => match ($state) {
                                                '1' => 'heroicon-o-check-circle',
                                                '0' => 'heroicon-o-x-mark',
                                                default => 'heroicon-o-x-mark',
                                            }),
                                        TextEntry::make('channel_count')
                                            ->label(__('Total Channels'))
                                            ->badge(),
                                        TextEntry::make('programme_count')
                                            ->label(__('Total Programmes'))
                                            ->badge(),
                                    ]),
                            ]),
                        Grid::make()
                            ->columns(1)
                            ->columnSpan(1)
                            ->schema([

                                KeyValueEntry::make('cached_epg_meta')
                                    ->label(__('Cache Metadata')),
                            ]),
                    ])
                    ->columns(3),

                Livewire::make(EpgViewer::class)
                    ->columnSpanFull(),
            ]);
    }
}
