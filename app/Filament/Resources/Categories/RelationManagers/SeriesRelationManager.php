<?php

namespace App\Filament\Resources\Categories\RelationManagers;

use App\Filament\Resources\Series\SeriesResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Hydrat\TableLayoutToggle\Concerns\HasToggleableTable;
use Illuminate\Database\Eloquent\Model;

class SeriesRelationManager extends RelationManager
{
    // use HasToggleableTable;

    protected static string $relationship = 'series';

    /** A merged category holds no series directly; it manages child categories instead. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ! $ownerRecord->is_merged;
    }

    protected $listeners = ['refreshRelation' => '$refresh'];

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(SeriesResource::getForm());
    }

    public function table(Table $table): Table
    {
        $table = $table->reorderRecordsTriggerAction(function ($action) {
            return $action->button()->label(__('Sort'));
        })->defaultSort('sort', 'asc')->reorderable('sort');

        return SeriesResource::setupTable($table, $this->ownerRecord->id);
    }
}
