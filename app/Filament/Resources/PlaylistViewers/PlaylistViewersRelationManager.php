<?php

namespace App\Filament\Resources\PlaylistViewers;

use App\Models\PlaylistViewer;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class PlaylistViewersRelationManager extends RelationManager
{
    protected static string $relationship = 'playlistViewers';

    protected static ?string $title = 'Viewers';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(100),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_admin')
                    ->label('Admin')
                    ->boolean()
                    ->trueIcon('heroicon-o-shield-check')
                    ->falseIcon('heroicon-o-user'),
                TextColumn::make('watch_progress_count')
                    ->label('Watch Records')
                    ->counts('watchProgress')
                    ->sortable(),
                TextColumn::make('ulid')
                    ->label('Viewer ID')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(function (array $data): PlaylistViewer {
                        return $this->getOwnerRecord()->playlistViewers()->create([
                            'ulid' => (string) Str::ulid(),
                            'name' => $data['name'],
                            'is_admin' => false,
                        ]);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->button()->hiddenLabel()->size('sm')
                    ->visible(fn (PlaylistViewer $record): bool => ! $record->is_admin),
                DeleteAction::make()
                    ->disabled(fn (PlaylistViewer $record): bool => $record->is_admin)
                    ->button()->hiddenLabel()->size('sm')
                    ->tooltip(fn (PlaylistViewer $record): ?string => $record->is_admin ? 'The Admin viewer cannot be deleted' : null),
            ], position: RecordActionsPosition::BeforeCells);
    }
}
