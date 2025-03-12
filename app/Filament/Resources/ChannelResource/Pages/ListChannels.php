<?php

namespace App\Filament\Resources\ChannelResource\Pages;

use App\Filament\Exports\ChannelExporter;
use App\Filament\Imports\ChannelImporter;
use App\Filament\Resources\ChannelResource;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\Playlist;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Hydrat\TableLayoutToggle\Concerns\HasToggleableTable;

class ListChannels extends ListRecords
{
    // use HasToggleableTable;

    protected static string $resource = ChannelResource::class;

    public function setPage($page, $pageName = 'page'): void
    {
        parent::setPage($page, $pageName);

        $this->dispatch('scroll-to-top');
    }

    protected function getHeaderActions(): array
    {
        return [
            // Actions\CreateAction::make(),
            Actions\Action::make('map')
                ->label('Map EPG to Playlist')
                ->form([
                    Forms\Components\Select::make('epg')
                        ->required()
                        ->label('EPG')
                        ->helperText('Select the EPG you would like to map from.')
                        ->options(Epg::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                        ->searchable(),
                    Forms\Components\Select::make('playlist')
                        ->required()
                        ->label('Playlist')
                        ->helperText('Select the playlist you would like to map to.')
                        ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                        ->searchable(),
                    Forms\Components\Toggle::make('overwrite')
                        ->label('Overwrite')
                        ->helperText('Overwrite channels with existing mappings?')
                        ->default(false),
                    Forms\Components\Toggle::make('recurring')
                        ->label('Recurring')
                        ->helperText('Re-run this mapping everytime the EPG is synced?')
                        ->default(false),
                ])
                ->action(function (Collection $records, array $data): void {
                    app('Illuminate\Contracts\Bus\Dispatcher')
                        ->dispatch(new \App\Jobs\MapPlaylistChannelsToEpg(
                            epg: (int)$data['epg'],
                            playlist: $data['playlist'],
                            force: $data['overwrite'],
                            recurring: $data['recurring'],
                        ));
                })->after(function () {
                    Notification::make()
                        ->success()
                        ->title('EPG to Channel mapping')
                        ->body('Channel mapping started, you will be notified when the process is complete.')
                        ->send();
                })
                ->requiresConfirmation()
                ->icon('heroicon-o-link')
                ->color('gray')
                ->modalIcon('heroicon-o-link')
                ->modalDescription('Map the selected EPG to the selected Playlist channels.')
                ->modalSubmitActionLabel('Map now'),
            Actions\ImportAction::make()
                ->importer(ChannelImporter::class)
                ->label('Import Channels')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('primary')
                ->modalDescription('Import channels from a CSV or XLSX file.'),
            Actions\ExportAction::make()
                ->exporter(ChannelExporter::class)
                ->label('Export Channels')
                ->icon('heroicon-m-arrow-up-tray')
                ->color('primary')
                ->modalDescription('Export channels to a CSV or XLSX file. NOTE: Only enabled channels will be exported.')
                ->columnMapping(false)
                ->modifyQueryUsing(function ($query, array $options) {
                    // For now, only allow exporting enabled channels
                    return $query->where([
                        ['playlist_id', $options['playlist']],
                        ['enabled', true],
                    ]);
                    // return $query->where('playlist_id', $options['playlist'])
                    //     ->when($options['enabled'], function ($query, $enabled) {
                    //         return $query->where('enabled', $enabled);
                    //     });
                }),
        ];
    }

    public function getTabs(): array
    {
        return self::setupTabs();
    }

    public static function setupTabs($relationId = null): array
    {
        // Change count based on view
        $totalCount = Channel::query()
            ->when($relationId, function ($query, $relationId) {
                return $query->where('group_id', $relationId);
            })->count();
        $enabledCount = Channel::query()->where('enabled', true)
            ->when($relationId, function ($query, $relationId) {
                return $query->where('group_id', $relationId);
            })->count();
        $disabledCount = Channel::query()->where('enabled', false)
            ->when($relationId, function ($query, $relationId) {
                return $query->where('group_id', $relationId);
            })->count();

        // Return tabs
        return [
            'all' => Tab::make('All Channels')
                ->badge($totalCount),
            'enabled' => Tab::make('Enabled Channels')
                // ->icon('heroicon-m-check')
                ->badgeColor('success')
                ->modifyQueryUsing(fn($query) => $query->where('enabled', true))
                ->badge($enabledCount),
            'disabled' => Tab::make('Disabled Channels')
                // ->icon('heroicon-m-x-mark')
                ->badgeColor('danger')
                ->modifyQueryUsing(fn($query) => $query->where('enabled', false))
                ->badge($disabledCount),
        ];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id());
    }
}
