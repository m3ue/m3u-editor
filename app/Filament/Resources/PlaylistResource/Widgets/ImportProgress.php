<?php

namespace App\Filament\Resources\PlaylistResource\Widgets;

use App\Enums\Status;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class ImportProgress extends Widget
{
    protected static string $view = 'filament.resources.playlist-resource.widgets.import-progress';

    public ?Model $record = null;

    public function getColumnSpan(): int|string|array
    {
        return 'full';
    }

    public function getProgressData(): array
    {
        $record = $this->record;
        $isProcessing = false;
        if ($record) {
            // Get fresh record to ensure we have the latest status
            $record = $record->newQuery()->find($record->getKey());
            $isProcessing = $record->status === Status::Processing || $record->status === Status::Pending;
        }
        return [
            'processing' => $isProcessing,
            'progress' => $record->progress ?? 100, // default to complete if no record
            'seriesProgress' => $record->series_progress ?? 100, // default to complete if no record
        ];
    }
}
