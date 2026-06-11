<?php

namespace App\Filament\Resources\MergedEpgs\Pages;

use App\Enums\Status;
use App\Facades\PlaylistFacade;
use App\Filament\Resources\Epgs\Widgets\ImportProgress;
use App\Filament\Resources\MergedEpgs\MergedEpgResource;
use App\Jobs\ProcessEpgImport;
use App\Livewire\EpgViewer;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewMergedEpg extends ViewRecord
{
    protected static string $resource = MergedEpgResource::class;

    public function getVisibleHeaderWidgets(): array
    {
        return [
            ImportProgress::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('process')
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
                        ->title(__('Merged EPG is processing'))
                        ->body(__('Merged EPG is being processed in the background. The view will update when complete.'))
                        ->duration(5000)
                        ->send();
                })
                ->disabled(fn () => $this->getRecord()->status === Status::Processing)
                ->requiresConfirmation()
                ->modalDescription(__('Process merged EPG now? This will regenerate the merged EPG output.'))
                ->modalSubmitActionLabel(__('Yes, process now')),
            ActionGroup::make([
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
        return $schema
            ->schema([
                Section::make(__('Merged EPG Information'))
                    ->collapsible(true)
                    ->compact()
                    ->persistCollapsed(true)
                    ->schema([
                        Grid::make()
                            ->columns(2)
                            ->columnSpan('full')
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
                                        TextEntry::make('sources_cached')
                                            ->label(__('Sources Cached'))
                                            ->badge()
                                            ->color(function ($record): string {
                                                $total = $record->sourceEpgs()->count();
                                                $cached = $record->sourceEpgs()->where('epgs.is_cached', true)->count();
                                                if ($cached === 0) {
                                                    return 'danger';
                                                }

                                                return $cached === $total ? 'success' : 'warning';
                                            })
                                            ->state(function ($record): string {
                                                $total = $record->sourceEpgs()->count();
                                                $cached = $record->sourceEpgs()->where('epgs.is_cached', true)->count();

                                                return "{$cached}/{$total}";
                                            }),
                                        TextEntry::make('source_epgs_count')
                                            ->label(__('Source EPGs'))
                                            ->badge()
                                            ->state(fn ($record) => $record->sourceEpgs()->count()),
                                        TextEntry::make('channel_count')
                                            ->label(__('Total Channels'))
                                            ->badge(),
                                        TextEntry::make('programme_count')
                                            ->label(__('Total Programmes'))
                                            ->badge(),
                                    ]),
                            ]),
                    ]),

                Livewire::make(EpgViewer::class)
                    ->columnSpanFull(),
            ]);
    }
}
