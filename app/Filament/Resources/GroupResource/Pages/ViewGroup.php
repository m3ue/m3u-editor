<?php

namespace App\Filament\Resources\GroupResource\Pages;

use App\Filament\Resources\GroupResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Collection;

class ViewGroup extends ViewRecord
{
    protected static string $resource = GroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('enable')
                ->label('Enable group channels')
                ->action(function ($record): void {
                    $record->channels()->update([
                        'enabled' => true,
                    ]);
                })->after(function ($livewire) {
                    $livewire->dispatch('refreshRelation');
                    Notification::make()
                        ->success()
                        ->title('Group channels enabled')
                        ->body('Group channels have been enabled.')
                        ->send();
                })
                ->requiresConfirmation()
                ->modalIcon('heroicon-o-check-circle')
                ->modalDescription('Enable all group channels now?')
                ->modalSubmitActionLabel('Yes, enable now'),
            Actions\Action::make('disable')
                ->label('Disable group channels')
                ->action(function ($record): void {
                    $record->channels()->update([
                        'enabled' => false,
                    ]);
                })->after(function ($livewire) {
                    $livewire->dispatch('refreshRelation');
                    Notification::make()
                        ->success()
                        ->title('Group channels disabled')
                        ->body('Group channels have been disabled.')
                        ->send();
                })
                ->color('danger')
                ->requiresConfirmation()
                ->modalIcon('heroicon-o-x-circle')
                ->modalDescription('Disable all group channels now?')
                ->modalSubmitActionLabel('Yes, disable now')
        ];
    }
}
