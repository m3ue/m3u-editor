<?php

namespace App\Filament\Resources\PostProcessResource\RelationManagers;

use App\Models\Epg;
use App\Models\Playlist;
use App\Tables\Columns\PivotNameColumn;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProcessesRelationManager extends RelationManager
{
    protected static string $relationship = 'processes';

    protected static ?string $title = 'Assigned to';

    protected $listeners = ['refreshRelation' => '$refresh'];

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('processable_type')
                    ->required()
                    ->label('Item type')
                    ->live()
                    ->helperText('The type of item to assign this post process to.')
                    ->options([
                        Playlist::class => 'Playlist',
                        Epg::class => 'EPG',
                    ])
                    ->default(Playlist::class) // Default to Playlists if no type is selected
                    ->searchable(),

                Forms\Components\Select::make('processable_id')
                    ->required()
                    ->label('Playlist')
                    ->helperText('Select the Playlist you would like to assign this post process to.')
                    ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                    ->hidden(fn($get) => $get('processable_type') !== Playlist::class)
                    ->searchable(),
                Forms\Components\Select::make('processable_id')
                    ->required()
                    ->label('EPG')
                    ->helperText('Select the EPG you would like to assign this post process to.')
                    ->options(Epg::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                    ->hidden(fn($get) => $get('processable_type') !== Epg::class)
                    ->searchable(),

                // @TODO: Add a select for the type of Event

                Forms\Components\TextInput::make('post_process_id')
                    ->label('Post Process ID')
                    ->default($this->ownerRecord->id)
                    ->hidden()
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                PivotNameColumn::make('name')
                    ->label('Model'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Assign processing to item')
                    ->modalHeading('Assign processing to item'),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->label('Remove post processing from item')
                    ->modalHeading('Remove post processing')
                    ->modalDescription('Remove post processing from item?')
                    ->modalSubmitActionLabel('Remove post processing')
                    ->icon('heroicon-o-x-circle')
                    ->modalIcon('heroicon-o-x-circle')
                    ->button()
                    ->hiddenLabel(),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Remove post processing')
                        ->modalHeading('Remove post processing')
                        ->modalDescription('Remove post processing from selected item?')
                        ->modalSubmitActionLabel('Remove')
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle'),
                ]),
            ]);
    }
}
