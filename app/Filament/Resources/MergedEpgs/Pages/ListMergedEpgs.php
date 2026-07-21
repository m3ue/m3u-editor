<?php

namespace App\Filament\Resources\MergedEpgs\Pages;

use App\Filament\Resources\MergedEpgs\MergedEpgResource;
use App\Jobs\ProcessEpgImport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListMergedEpgs extends ListRecords
{
    protected static string $resource = MergedEpgResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Combine multiple EPGs into one unified source for external players or EPG mapping.');
    }

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
