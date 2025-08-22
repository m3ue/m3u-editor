<?php

namespace App\Filament\Resources\Playlists\RelationManagers;

use App\Filament\Resources\Playlists\Resources\PlaylistSyncStatuses\PlaylistSyncStatusResource;
use Filament\Actions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class SyncStatusesRelationManager extends RelationManager
{
    protected static string $relationship = 'syncStatuses';

    protected static ?string $relatedResource = PlaylistSyncStatusResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->headerActions([
                Actions\CreateAction::make(),
            ]);
    }
}
