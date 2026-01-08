<?php

namespace App\Filament\GuestPanel\Resources\Vods\Pages;

use App\Filament\GuestPanel\Pages\Concerns\HasPlaylist;
use App\Filament\GuestPanel\Resources\Vods\VodResource;
use Filament\Actions;
use Filament\Infolists;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas;
use Filament\Schemas\Components\Section;
use Illuminate\Contracts\Support\Htmlable;

class ViewVod extends ViewRecord
{
    use HasPlaylist;

    protected static string $resource = VodResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label('Back to VOD')
                ->url(VodResource::getUrl('index'))
                ->icon('heroicon-s-arrow-left')
                ->color('gray')
                ->size('sm'),
        ];
    }

    public function infolist(Schemas\Schema $schema): Schemas\Schema
    {
        return $schema
            ->components([
                Section::make('Channel Details')
                    ->icon('heroicon-m-information-circle')
                    ->columnSpanFull()
                    ->compact()
                    ->collapsible(true)
                    ->columns(3)
                    ->persistCollapsed(true)
                    ->schema([
                        // Infolists\Components\TextEntry::make('url')
                        //     ->label('URL')->columnSpanFull(),
                        // Infolists\Components\TextEntry::make('proxy_url')
                        //     ->label('Proxy URL')->columnSpanFull(),
                        Infolists\Components\TextEntry::make('stream_id')
                            ->label('Stream ID'),
                        Infolists\Components\TextEntry::make('title')
                            ->label('Title'),
                        Infolists\Components\TextEntry::make('name')
                            ->label('Name'),
                        Infolists\Components\TextEntry::make('channel')
                            ->label('Channel'),
                        Infolists\Components\TextEntry::make('group')
                            ->badge()
                            ->label('Group'),
                    ]),
            ]);
    }
}
