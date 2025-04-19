<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EpgChannelResource\Pages;
use App\Filament\Resources\EpgChannelResource\RelationManagers;
use App\Models\EpgChannel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EpgChannelResource extends Resource
{
    protected static ?string $model = EpgChannel::class;

    //    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'display_name'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('user_id', auth()->id());
    }

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $label = 'Channels';

    protected static ?string $navigationGroup = 'EPG';

    public static function getNavigationSort(): ?int
    {
        return 5;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return self::setupTable($table);
    }

    public static function setupTable(Table $table, $relationId = null): Table
    {
        return $table
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->deferLoading()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\ImageColumn::make('icon')
                    ->checkFileExistence(false)
                    ->toggleable()
                    ->height(40)
                    ->width('auto'),
                Tables\Columns\TextInputColumn::make('display_name')
                    ->sortable()
                    ->tooltip('Display Name')
                    ->toggleable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->limit(40)
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('lang')
                    ->sortable()
                    ->toggleable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('channel_id')
                    ->sortable()
                    ->toggleable()
                    ->searchable(),
                // WARNING! Slows table load quite a bit...
                // Tables\Columns\TextColumn::make('programmes_count')
                //     ->label('Programs')
                //     ->counts('programmes')
                //     ->sortable(),
                Tables\Columns\TextColumn::make('epg.name')
                    ->sortable()
                    ->numeric()
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('epg')
                    ->relationship('epg', 'name')
                    ->hidden(fn() => $relationId)
                    ->multiple()
                    ->preload()
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->slideOver()
                    ->button()
                    ->hiddenLabel(),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                // Tables\Actions\BulkActionGroup::make([
                //     Tables\Actions\DeleteBulkAction::make(),
                // ]),
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
            'index' => Pages\ListEpgChannels::route('/'),
            // 'create' => Pages\CreateEpgChannel::route('/create'),
            // 'edit' => Pages\EditEpgChannel::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        return [
            // Forms\Components\TextInput::make('name')
            //     ->required()
            //     ->maxLength(255),
            Forms\Components\TextInput::make('icon')
                ->prefixIcon('heroicon-m-globe-alt')
                ->url(),
            Forms\Components\TextInput::make('display_name')
                ->maxLength(255),
            // Forms\Components\TextInput::make('lang')
            //     ->maxLength(255),
            // Forms\Components\TextInput::make('channel_id')
            //     ->maxLength(255),
            // Forms\Components\Select::make('epg_id')
            //     ->relationship('epg', 'name')
            //     ->required(),
        ];
    }
}
