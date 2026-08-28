<?php

namespace App\Filament\Resources\MergedGroups\Pages;

use App\Filament\Resources\MergedGroups\MergedGroupResource;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class ListMergedGroups extends ListRecords
{
    protected static string $resource = MergedGroupResource::class;

    /** Matches the resource's $groupType; the CreateAction stamps it onto new rows. */
    protected static string $groupType = 'live';

    public function getSubheading(): string|Htmlable|null
    {
        return __('Merge several groups into one. Their channels keep their own group untouched and are delivered under the merged name.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->slideOver()
                ->using(function (array $data, string $model): Model {
                    $data['user_id'] = auth()->id();
                    $data['custom'] = true;
                    $data['is_merged'] = true;
                    $data['type'] = static::$groupType;
                    $data['name_internal'] = $data['name'];

                    return $model::create($data);
                })
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title(__('Merged group created'))
                        ->body(__('Use "Manage groups" to merge groups into it.')),
                ),
        ];
    }
}
