<?php

namespace App\Filament\Resources\PlaylistResource\Pages;

use App\Filament\Infolists\Components\EpgViewer;
use App\Filament\Resources\PlaylistResource;
use Filament\Actions;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPlaylist extends ViewRecord
{
    protected static string $resource = PlaylistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // ...
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Playlist Information')
                    ->schema([
                        TextEntry::make('name')
                            ->label('Name'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn($state) => $state?->getColor()),
                        TextEntry::make('synced')
                            ->label('Last Synced')
                            ->since()
                            ->placeholder('Never'),
                        TextEntry::make('channels_count')
                            ->label('Total Channels'),
                    ])
                    ->columns(2),

                Section::make('EPG Guide')
                    ->schema([
                        EpgViewer::make(),
                    ])
                    ->collapsible(false),
            ]);
    }
}
