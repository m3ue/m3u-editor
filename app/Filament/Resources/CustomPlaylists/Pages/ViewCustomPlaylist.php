<?php

namespace App\Filament\Resources\CustomPlaylists\Pages;

use App\Facades\PlaylistFacade;
use App\Filament\Resources\CustomPlaylists\CustomPlaylistResource;
use App\Filament\Resources\CustomPlaylists\RelationManagers\CategoriesRelationManager;
use App\Filament\Resources\CustomPlaylists\RelationManagers\ChannelsRelationManager;
use App\Filament\Resources\CustomPlaylists\RelationManagers\GroupsRelationManager;
use App\Filament\Resources\CustomPlaylists\RelationManagers\SeriesRelationManager;
use App\Filament\Resources\CustomPlaylists\RelationManagers\VodRelationManager;
use App\Livewire\EpgViewer;
use App\Livewire\MediaFlowProxyUrl;
use App\Livewire\PlaylistEpgUrl;
use App\Livewire\PlaylistInfo;
use App\Livewire\PlaylistM3uUrl;
use App\Livewire\XtreamApiInfo;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class ViewCustomPlaylist extends ViewRecord
{
    protected static string $resource = CustomPlaylistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label(__('Edit Playlist'))
                ->color('gray')
                ->icon('heroicon-m-pencil'),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        $extraLinks = [];
        if (PlaylistFacade::mediaFlowProxyEnabled()) {
            $extraLinks[] = Livewire::make(MediaFlowProxyUrl::class);
        }
        $extraLinks[] = Livewire::make(PlaylistEpgUrl::class);

        return $schema
            ->schema([
                Tabs::make()
                    ->persistTabInQueryString()
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make(__('Details'))
                            ->icon('heroicon-o-play')
                            ->schema([
                                Livewire::make(PlaylistInfo::class),
                            ]),
                        Tab::make(__('Links'))
                            ->icon('heroicon-m-link')
                            ->schema([
                                Section::make()
                                    ->columns(2)
                                    ->schema([
                                        Grid::make()
                                            ->columnSpan(1)
                                            ->columns(1)
                                            ->schema([
                                                Livewire::make(PlaylistM3uUrl::class)
                                                    ->columnSpanFull(),
                                            ]),
                                        Grid::make()
                                            ->columnSpan(1)
                                            ->columns(1)
                                            ->schema($extraLinks),
                                    ]),
                            ]),
                        Tab::make(__('Xtream API'))
                            ->icon('heroicon-m-bolt')
                            ->schema([
                                Section::make()
                                    ->columns(1)
                                    ->schema([
                                        Livewire::make(XtreamApiInfo::class),
                                    ]),
                            ]),
                    ])->contained(false),
                Livewire::make(EpgViewer::class)
                    ->columnSpanFull(),
            ]);
    }

    public function getRelationManagers(): array
    {
        return [
            ChannelsRelationManager::class,
            GroupsRelationManager::class,
            VodRelationManager::class,
            SeriesRelationManager::class,
            CategoriesRelationManager::class,
        ];
    }
}
