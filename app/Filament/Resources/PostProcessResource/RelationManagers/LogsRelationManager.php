<?php

namespace App\Filament\Resources\PostProcessResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LogsRelationManager extends RelationManager
{
    protected static string $relationship = 'logs';

    public function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Item Name')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Process Status')
                    ->sortable()
                    ->badge()
                    ->toggleable()
                    ->color(fn($state) => $state === 'success' ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('type')
                    ->label('Process Event')
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('message')
                    ->label('Process Message')
                    ->wrap(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ran at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->button()
                    ->hiddenLabel()
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
