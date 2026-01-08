<?php

namespace App\Filament\Resources\Epgs\Widgets;

use App\Enums\EpgSourceType;
use App\Enums\Status;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class ImportProgress extends Widget
{
    protected string $view = 'filament.resources.epg-resource.widgets.import-progress';

    public ?Model $record = null;

    public function getColumnSpan(): int|string|array
    {
        return 'full';
    }

    public function getProgressData(): array
    {
        $record = $this->record;
        $isProcessing = false;
        $type = null;
        if ($record) {
            // Get fresh record to ensure we have the latest status
            $record = $record->newQuery()->find($record->getKey());
            $isProcessing = $record->status === Status::Processing || $record->status === Status::Pending;
            $type = $record->source_type ?? null;
        }

        return [
            'processing' => $isProcessing,
            'progress' => round($record->progress ?? 100, 2), // default to complete if no record
            'sdProgress' => $type === EpgSourceType::SCHEDULES_DIRECT ? round($record->sd_progress ?? 100, 2) : null, // null if not SD
            'cacheProgress' => round($record->cache_progress ?? 100, 2), // default to complete if no record
        ];
    }
}
