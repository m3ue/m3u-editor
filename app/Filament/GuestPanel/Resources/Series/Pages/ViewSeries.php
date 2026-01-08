<?php

namespace App\Filament\GuestPanel\Resources\Series\Pages;

use App\Filament\GuestPanel\Pages\Concerns\HasPlaylist;
use App\Filament\GuestPanel\Resources\Series\SeriesResource;
use Filament\Actions;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas;
use Filament\Schemas\Components\Section;
use Illuminate\Contracts\Support\Htmlable;

class ViewSeries extends ViewRecord
{
    use HasPlaylist;

    protected static string $resource = SeriesResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label('Back to series')
                ->url(SeriesResource::getUrl('index'))
                ->icon('heroicon-s-arrow-left')
                ->color('gray')
                ->size('sm'),
        ];
    }

    public function infolist(Schemas\Schema $schema): Schemas\Schema
    {
        return $schema
            ->components([
                Section::make('Series info')
                    ->icon('heroicon-m-information-circle')
                    ->columnSpanFull()
                    ->compact()
                    ->collapsible(true)
                    ->persistCollapsed(true)
                    ->schema([
                        TextEntry::make('plot')
                            ->label('Description')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
