<?php

namespace App\Filament\Resources\Playlists\Resources\PlaylistSyncRuns;

use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Resources\Playlists\Resources\PlaylistSyncRuns\Pages\ListPlaylistSyncRuns;
use App\Filament\Resources\Playlists\Resources\PlaylistSyncRuns\Pages\ViewPlaylistSyncRun;
use App\Filament\Resources\SyncRuns\SyncRunResource;
use App\Models\SyncRun;
use BackedEnum;
use Filament\Resources\ParentResourceRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Per-playlist scoped view of {@see SyncRun} records, registered as a nested
 * resource under {@see PlaylistResource}. The parent registration scopes the
 * query to `playlist->syncRuns()` automatically — no manual filtering needed.
 *
 * Form/infolist/table definitions are reused from the top-level
 * {@see SyncRunResource} so both views stay in sync.
 */
class PlaylistSyncRunResource extends Resource
{
    protected static ?string $model = SyncRun::class;

    protected static ?string $parentResource = PlaylistResource::class;

    protected static ?string $recordTitleAttribute = 'uuid';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $label = 'Sync Run';

    protected static ?string $pluralLabel = 'Sync Runs';

    public static function getParentResourceRegistration(): ?ParentResourceRegistration
    {
        return PlaylistResource::asParent()
            ->relationship('syncRuns');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return SyncRunResource::infolist($schema);
    }

    public static function table(Table $table): Table
    {
        return SyncRunResource::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlaylistSyncRuns::route('/'),
            'view' => ViewPlaylistSyncRun::route('/{record}'),
        ];
    }
}
