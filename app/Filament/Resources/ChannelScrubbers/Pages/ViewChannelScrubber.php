<?php

namespace App\Filament\Resources\ChannelScrubbers\Pages;

use App\Filament\Resources\ChannelScrubbers\ChannelScrubberResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Infolists;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

class ViewChannelScrubber extends ViewRecord
{
    protected static string $resource = ChannelScrubberResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Scrubber Details')
                ->columns(3)
                ->compact()
                ->collapsed(true)
                ->schema([
                    Infolists\Components\TextEntry::make('name'),
                    Infolists\Components\TextEntry::make('playlist.name')
                        ->label('Playlist')
                        ->badge(),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->color(fn ($state) => $state->getColor()),
                    Infolists\Components\TextEntry::make('check_method')
                        ->label('Check Method')
                        ->formatStateUsing(fn (string $state): string => strtoupper($state))
                        ->badge()
                        ->color(fn (string $state): string => $state === 'ffprobe' ? 'warning' : 'info'),
                    Infolists\Components\TextEntry::make('channel_count')
                        ->label('Last Channels Checked'),
                    Infolists\Components\TextEntry::make('dead_count')
                        ->label('Last Dead Links')
                        ->badge()
                        ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
                    Infolists\Components\IconEntry::make('include_vod')
                        ->label('Includes VOD')
                        ->boolean(),
                    Infolists\Components\IconEntry::make('scan_all')
                        ->label('Scans All Channels')
                        ->boolean(),
                    Infolists\Components\IconEntry::make('recurring')
                        ->label('Recurring')
                        ->boolean(),
                    Infolists\Components\TextEntry::make('sync_time')
                        ->label('Last Runtime')
                        ->formatStateUsing(fn ($state): string => $state ? gmdate('H:i:s', (int) $state) : '-'),
                    Infolists\Components\TextEntry::make('last_run_at')
                        ->label('Last Ran')
                        ->since(),
                ]),
        ]);
    }
}
