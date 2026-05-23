<?php

namespace App\Filament\Resources\DvrRecordings\Pages;

use App\Filament\Resources\DvrRecordings\DvrRecordingResource;
use Filament\Resources\Pages\ListRecords;

class ListDvrRecordings extends ListRecords
{
    protected static string $resource = DvrRecordingResource::class;
}
