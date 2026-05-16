<?php

namespace App\Filament\Resources\Playlists\RelationManagers;

use App\Filament\Resources\Playlists\Resources\SyncRuns\SyncRunResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class SyncRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'syncRuns';

    protected static ?string $relatedResource = SyncRunResource::class;

    public function table(Table $table): Table
    {
        return $table;
    }
}
