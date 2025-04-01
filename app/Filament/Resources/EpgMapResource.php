<?php

namespace App\Filament\Resources;

use App\Enums\EpgStatus;
use App\Filament\Resources\EpgMapResource\Pages;
use App\Filament\Resources\EpgMapResource\RelationManagers;
use App\Models\EpgMap;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use RyanChandler\FilamentProgressColumn\ProgressColumn;

class EpgMapResource extends Resource
{
    protected static ?string $model = EpgMap::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $label = 'EPG Map';
    protected static ?string $pluralLabel = 'EPG Maps';

    protected static ?string $navigationGroup = 'Playlist';

    public static function getNavigationSort(): ?int
    {
        return 5;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll()
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->toggleable()
                    ->color(fn(EpgStatus $state) => $state->getColor()),
                ProgressColumn::make('progress')
                    ->sortable()
                    ->poll(fn($record) => $record->status === EpgStatus::Processing || $record->status === EpgStatus::Pending ? '3s' : null)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('channel_count')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('mapped_count')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('override')
                    ->toggleable()
                    ->tooltip((fn(EpgMap $record) => $record->playlist_id !== null ? 'Override existing EPG mappings' : 'Not available for custom channel mappings'))
                    ->disabled((fn(EpgMap $record) => $record->playlist_id === null))
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('recurring')
                    ->toggleable()
                    ->tooltip((fn(EpgMap $record) => $record->playlist_id !== null ? 'Run again on EPG sync' : 'Not available for custom channel mappings'))
                    ->disabled((fn(EpgMap $record) => $record->playlist_id === null))
                    ->sortable(),
                Tables\Columns\TextColumn::make('sync_time')
                    ->label('Sync Time')
                    ->formatStateUsing(fn(string $state): string => gmdate('H:i:s', (int)$state))
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('mapped_at')
                    ->label('Last ran')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->button()
                    ->hiddenLabel(),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEpgMaps::route('/'),
            // 'create' => Pages\CreateEpgMap::route('/create'),
            // 'edit' => Pages\EditEpgMap::route('/{record}/edit'),
        ];
    }
}
