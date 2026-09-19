<?php

namespace App\Filament\Resources\Epgs\Concerns;

use App\Services\SchedulesDirectService;

trait ValidatesSchedulesDirectLineupSelection
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validateSchedulesDirectLineupSelection(array $data): array
    {
        if (($data['source_type'] ?? null) !== 'schedules_direct') {
            return $data;
        }

        $lineupIds = $data['sd_lineup_ids'] ?? [];
        if (! is_array($lineupIds)) {
            $lineupIds = [$lineupIds];
        }

        $auth = app(SchedulesDirectService::class)->authenticate(
            (string) ($data['sd_username'] ?? ''),
            (string) ($data['sd_password'] ?? '')
        );
        $lineupIds = app(SchedulesDirectService::class)->validateEpgLineupSelection($auth['token'], $lineupIds);

        $data['sd_lineup_ids'] = $lineupIds;
        $data['sd_lineup_id'] = $lineupIds[0] ?? null;

        return $data;
    }
}
