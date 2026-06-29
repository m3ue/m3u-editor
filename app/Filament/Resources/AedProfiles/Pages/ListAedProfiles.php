<?php

namespace App\Filament\Resources\AedProfiles\Pages;

use App\Filament\Resources\AedProfiles\AedProfileResource;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ListAedProfiles extends ListRecords
{
    protected static string $resource = AedProfileResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Advanced EPG Dummies (AED) — extract live event info from stream titles to generate smart EPG programmes.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(function (array $data, string $model): Model {
                    $data['user_id'] = auth()->id();

                    return $model::create($data);
                })
                ->slideOver()
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title(__('AED Profile created'))
                        ->body(__('Assign this profile to channels or groups to enable smart EPG extraction.')),
                )
                ->successRedirectUrl(fn ($record): string => EditAedProfile::getUrl(['record' => $record])),
        ];
    }

    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id());
    }
}
