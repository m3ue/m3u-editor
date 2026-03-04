<?php

namespace App\Filament\Resources\Series\Pages;

use App\Filament\Resources\Series\RelationManagers\EpisodesRelationManager;
use App\Filament\Resources\Series\SeriesResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewSeries extends ViewRecord
{
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
                ->label('Back to Series')
                ->url(SeriesResource::getUrl('index'))
                ->icon('heroicon-s-arrow-left')
                ->color('gray'),
            Actions\EditAction::make()
                ->label('Edit Series')
                ->slideOver()
                ->color('gray')
                ->icon('heroicon-s-pencil'),
            Actions\Action::make('toggle_enabled')
                ->label(fn () => $this->record->enabled ? 'Disable Series' : 'Enable Series')
                ->icon(fn () => $this->record->enabled ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                ->color(fn () => $this->record->enabled ? 'danger' : 'success')
                ->action(function () {
                    $this->record->update(['enabled' => ! $this->record->enabled]);
                    $this->refreshFormData(['enabled']);
                })
                ->requiresConfirmation(),
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            EpisodesRelationManager::class,
        ];
    }
}
