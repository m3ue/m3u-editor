<?php

namespace App\Filament\Resources\VodGroups\RelationManagers;

use App\Filament\Resources\Vods\Pages\ListVod;
use App\Filament\Resources\Vods\VodResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class VodRelationManager extends RelationManager
{
    protected static string $relationship = 'vod_channels';

    /** A merged group owns no channels directly; it manages child groups instead. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ! $ownerRecord->is_merged;
    }

    protected static ?string $label = 'VOD Channels';

    protected static ?string $pluralLabel = 'VOD Channels';

    protected static ?string $title = 'VOD Channels';

    public static function getNavigationLabel(): string
    {
        return __('VOD Channels');
    }

    protected $listeners = ['refreshRelation' => '$refresh'];

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(VodResource::getForm());
    }

    public function infolist(Schema $schema): Schema
    {
        return VodResource::infolist($schema);
    }

    public function table(Table $table): Table
    {
        $table = $table->reorderRecordsTriggerAction(function ($action) {
            return $action->button()->label(__('Sort'));
        })->defaultSort('sort', 'asc')->reorderable('sort');

        return VodResource::setupTable($table, $this->ownerRecord->id);
    }

    public function getTabs(): array
    {
        return ListVod::setupTabs($this->ownerRecord->id);
    }
}
