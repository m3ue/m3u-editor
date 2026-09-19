<?php

namespace App\Filament\Resources\Epgs\Pages;

use App\Filament\Resources\Epgs\Concerns\ValidatesSchedulesDirectLineupSelection;
use App\Filament\Resources\Epgs\EpgResource;
use App\Models\Epg;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEpg extends EditRecord
{
    use ValidatesSchedulesDirectLineupSelection;

    protected static string $resource = EpgResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EpgResource::getManageSdLineupsAction(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->validateSchedulesDirectLineupSelection($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Epg $record */
        $record = $this->getRecord();
        $data['sd_lineup_ids'] = $record->configuredSchedulesDirectLineupIds();

        return $data;
    }
}
