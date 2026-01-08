<?php

namespace App\Filament\Resources\PostProcesses\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

class LogsRelationManager extends RelationManager
{
    protected static string $relationship = 'logs';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Item Name')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Process Status')
                    ->sortable()
                    ->badge()
                    ->toggleable()
                    ->color(function ($state) {
                        return match (strtolower($state)) {
                            'success' => 'success',
                            'error' => 'danger',
                            'skipped' => 'warning',
                            default => 'secondary'
                        };
                    }),
                TextColumn::make('type')
                    ->label('Process Event')
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('message')
                    ->label('Process Message')
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label('Ran at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([])
            ->recordActions([
                DeleteAction::make()
                    ->button()
                    ->hiddenLabel(),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
