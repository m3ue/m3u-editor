<?php

namespace App\Filament\GuestPanel\Resources\Series\Pages;

use App\Filament\GuestPanel\Pages\Concerns\HasPlaylist;
use App\Filament\GuestPanel\Resources\Series\SeriesResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewSeries extends ViewRecord
{
    use HasPlaylist;

    protected static string $resource = SeriesResource::class;

    protected string $view = 'filament.resources.series.pages.view-series';

    public function getTitle(): string|Htmlable
    {
        return $this->record->name;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $parts = [];

        if ($this->record->release_date) {
            $parts[] = $this->record->release_date;
        }

        if ($this->record->genre) {
            $parts[] = $this->record->genre;
        }

        if ($this->record->rating) {
            $parts[] = '★ '.$this->record->rating;
        }

        return implode(' • ', $parts) ?: null;
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
}
