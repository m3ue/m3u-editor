<?php

namespace App\Filament\Resources\GroupResource\RelationManagers;

use App\Filament\Resources\ChannelResource;
use App\Filament\Resources\ChannelResource\Pages\ListChannels;
use App\Models\Channel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Hydrat\TableLayoutToggle\Concerns\HasToggleableTable;

class ChannelsRelationManager extends RelationManager
{
    // use HasToggleableTable;

    protected static string $relationship = 'channels';

    protected $listeners = ['refreshRelation' => '$refresh'];

    public function form(Form $form): Form
    {
        return $form
            ->schema(ChannelResource::getForm());
    }

    public function table(Table $table): Table
    {
        return ChannelResource::setupTable($table, $this->ownerRecord->id);
    }

    public function getTabs(): array
    {
        return ListChannels::setupTabs($this->ownerRecord->id);
    }
}
