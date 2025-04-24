<?php

namespace App\Filament\Resources\PlaylistResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SyncStatusesRelationManager extends RelationManager
{
    protected static string $relationship = 'syncStatuses';

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
                    ->label('Playlist Name')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('deleted_groups')
                    ->getStateUsing(fn($record) => count($record->deleted_groups)),
                Tables\Columns\TextColumn::make('added_groups')
                    ->getStateUsing(fn($record) => count($record->added_groups)),
                Tables\Columns\TextColumn::make('deleted_channels')
                    ->getStateUsing(fn($record) => count($record->deleted_channels)),
                Tables\Columns\TextColumn::make('added_channels')
                    ->getStateUsing(fn($record) => count($record->added_channels)),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Synced')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()
                        ->infolist([
                            Infolists\Components\Tabs::make('Tabs')
                                ->tabs([
                                    Infolists\Components\Tabs\Tab::make('Stats')
                                        ->schema([
                                            Infolists\Components\TextEntry::make('name')
                                                ->label('Playlist name'),
                                            Infolists\Components\TextEntry::make('sync_stats.time_rounded')
                                                ->label('Sync time')
                                                ->helperText('Total time to sync playlist (in seconds)'),
                                        ]),
                                    Infolists\Components\Tabs\Tab::make('Added groups')
                                        ->schema([
                                            Infolists\Components\RepeatableEntry::make('added_groups')
                                                ->schema([
                                                    Infolists\Components\TextEntry::make('name')
                                                        ->columnSpan(2),
                                                ])
                                                ->columns(2)
                                        ]),
                                    Infolists\Components\Tabs\Tab::make('Added channels')
                                        ->schema([
                                            Infolists\Components\RepeatableEntry::make('added_channels')
                                                ->schema([
                                                    Infolists\Components\TextEntry::make('title'),
                                                    Infolists\Components\TextEntry::make('name'),
                                                ])
                                                ->columns(2)
                                        ]),
                                    Infolists\Components\Tabs\Tab::make('Removed groups')
                                        ->schema([
                                            Infolists\Components\RepeatableEntry::make('deleted_groups')
                                                ->schema([
                                                    Infolists\Components\TextEntry::make('name')
                                                        ->columnSpan(2),
                                                ])
                                                ->columns(2)
                                        ]),
                                    Infolists\Components\Tabs\Tab::make('Removed channels')
                                        ->schema([
                                            Infolists\Components\RepeatableEntry::make('deleted_channels')
                                                ->schema([
                                                    Infolists\Components\TextEntry::make('title'),
                                                    Infolists\Components\TextEntry::make('name'),
                                                ])
                                                ->columns(2)
                                        ]),
                                ])
                        ])
                        ->slideOver(),
                    Tables\Actions\DeleteAction::make(),
                ])->button()->hiddenLabel()
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
