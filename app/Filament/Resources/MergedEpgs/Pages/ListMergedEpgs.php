<?php

namespace App\Filament\Resources\MergedEpgs\Pages;

use App\Filament\Resources\MergedEpgs\MergedEpgResource;
use App\Jobs\ProcessEpgImport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMergedEpgs extends ListRecords
{
    protected static string $resource = MergedEpgResource::class;

    protected ?string $subheading = 'Combine multiple EPGs into one unified XML for external players.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->slideOver()
                ->after(function ($record): void {
                    app('Illuminate\Contracts\Bus\Dispatcher')
                        ->dispatch(new ProcessEpgImport($record, force: true));
                }),
        ];
    }
}
