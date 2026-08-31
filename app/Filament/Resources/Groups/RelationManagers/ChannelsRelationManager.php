<?php

namespace App\Filament\Resources\Groups\RelationManagers;

use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\Channels\Pages\ListChannels;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Hydrat\TableLayoutToggle\Concerns\HasToggleableTable;
use Illuminate\Database\Eloquent\Model;

class ChannelsRelationManager extends RelationManager
{
    // use HasToggleableTable;

    protected static string $relationship = 'live_channels';

    /** A merged group owns no channels directly; it manages child groups instead. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ! $ownerRecord->is_merged;
    }

    protected static ?string $label = 'Live Channels';

    protected static ?string $pluralLabel = 'Live Channels';

    protected static ?string $title = 'Live Channels';

    public static function getNavigationLabel(): string
    {
        return __('Live Channels');
    }

    protected $listeners = ['refreshRelation' => '$refresh'];

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(ChannelResource::getForm());
    }

    public function infolist(Schema $schema): Schema
    {
        return ChannelResource::infolist($schema);
    }

    public function table(Table $table): Table
    {
        $table = $table->reorderRecordsTriggerAction(function ($action) {
            return $action->button()->label(__('Sort'));
        })->defaultSort('sort', 'asc')->reorderable('sort');

        return ChannelResource::setupTable($table, $this->ownerRecord->id);
    }

    public function getTabs(): array
    {
        return ListChannels::setupTabs($this->ownerRecord->id);
    }
}
