<?php

namespace App\Filament\Resources\MergedCategories\Pages;

use App\Filament\Resources\MergedCategories\MergedCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class ListMergedCategories extends ListRecords
{
    protected static string $resource = MergedCategoryResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Merge several series categories into one. Their series keep their own category untouched and are delivered under the merged name.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->slideOver()
                ->using(function (array $data, string $model): Model {
                    $data['user_id'] = auth()->id();
                    $data['is_merged'] = true;
                    $data['name_internal'] = $data['name'];

                    return $model::create($data);
                })
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title(__('Merged category created'))
                        ->body(__('Use "Manage categories" to merge categories into it.')),
                ),
        ];
    }
}
