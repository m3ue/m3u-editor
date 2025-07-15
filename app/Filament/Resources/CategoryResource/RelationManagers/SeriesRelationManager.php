<?php

namespace App\Filament\Resources\CategoryResource\RelationManagers;

use App\Filament\Resources\SeriesResource;
use App\Filament\Resources\SeriesResource\Pages\ListSeries;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Hydrat\TableLayoutToggle\Concerns\HasToggleableTable;

class SeriesRelationManager extends RelationManager
{
    // use HasToggleableTable;

    protected static string $relationship = 'series';

    protected $listeners = ['refreshRelation' => '$refresh'];

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema(SeriesResource::getForm());
    }

    public function table(Table $table): Table
    {
        return SeriesResource::setupTable($table, $this->ownerRecord->id);
    }
}
