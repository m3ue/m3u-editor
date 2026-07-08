<?php

namespace App\Filament\Resources\PostProcesses\RelationManagers;

use App\Models\Epg;
use App\Models\Playlist;
use App\Services\DateFormatService;
use App\Tables\Columns\PivotNameColumn;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

class ProcessesRelationManager extends RelationManager
{
    protected static string $relationship = 'processes';

    protected static ?string $title = 'Assigned to';

    protected $listeners = ['refreshRelation' => '$refresh'];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('processable_type')
                    ->required()
                    ->label(__('Item type'))
                    ->live()
                    ->helperText(__('The type of item to assign this post process to.'))
                    ->options([
                        (new Playlist)->getMorphClass() => 'Playlist',
                        Epg::class => 'EPG',
                    ])
                    ->default((new Playlist)->getMorphClass())
                    ->searchable(),

                Select::make('processable_id')
                    ->required()
                    ->label(__('Playlist'))
                    ->helperText(__('Select the Playlist you would like to assign this post process to.'))
                    ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                    ->hidden(fn ($get) => $get('processable_type') !== (new Playlist)->getMorphClass())
                    ->searchable(),
                Select::make('processable_id')
                    ->required()
                    ->label(__('EPG'))
                    ->helperText(__('Select the EPG you would like to assign this post process to.'))
                    ->options(Epg::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                    ->hidden(fn ($get) => $get('processable_type') !== Epg::class)
                    ->searchable(),

                // @TODO: Add a select for the type of Event

                TextInput::make('post_process_id')
                    ->label(__('Post Process ID'))
                    ->default($this->ownerRecord->id)
                    ->hidden(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                PivotNameColumn::make('name')
                    ->label(__('Model')),
                TextColumn::make('created_at')
                    ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Assign processing to item'))
                    ->modalHeading(__('Assign processing to item')),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label(__('Remove post processing from item'))
                    ->modalHeading(__('Remove post processing'))
                    ->modalDescription(__('Remove post processing from item?'))
                    ->modalSubmitActionLabel(__('Remove post processing'))
                    ->icon('heroicon-o-x-circle')
                    ->modalIcon('heroicon-o-x-circle')
                    ->button()
                    ->hiddenLabel(),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label(__('Remove post processing'))
                        ->modalHeading(__('Remove post processing'))
                        ->modalDescription(__('Remove post processing from selected item?'))
                        ->modalSubmitActionLabel(__('Remove'))
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle'),
                ]),
            ]);
    }
}
