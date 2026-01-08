<?php

namespace App\Filament\Resources\PersonalAccessTokens;

use App\Models\PersonalAccessToken;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PersonalAccessTokenResource extends Resource
{
    protected static ?string $model = PersonalAccessToken::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Tools';

    protected static ?string $navigationLabel = 'API Tokens';

    protected static ?string $breadcrumb = 'API Tokens';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'abilities'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('tokenable_id', auth()->id());
    }

    public static function getNavigationSort(): ?int
    {
        return 0;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components(self::getForm());
    }

    public static function getForm(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->label('Token Name')
                ->required()
                ->maxLength(255)
                ->columnSpanFull()
                ->placeholder('Enter Token Name'),
            Forms\Components\Select::make('abilities')
                ->label('Permissions')
                ->multiple()
                ->required()
                ->options([
                    'create' => 'Create',
                    'view' => 'View',
                    'update' => 'Update',
                    'delete' => 'Delete',
                ])->default(['create', 'view', 'update']),
            Forms\Components\DatePicker::make('expires_at')
                ->label('Expiration Date')
                ->helperText('Select Expiration Date, or leave empty for no expiration')
                ->minDate(now()->addDays(1))
                ->maxDate(now()->addYears(10)),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Token Name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('abilities')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_used_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('expires_at')
                    ->date()
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
                //
            ])
            ->recordActions([
                Actions\DeleteAction::make()
                    ->modalDescription('Are you sure you want to delete this token? This action cannot be undone.')
                    ->button()->hiddenLabel()->size('sm'),
                Actions\EditAction::make()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListPersonalAccessTokens::route('/'),
            // 'create' => Pages\CreatePersonalAccessToken::route('/create'),
            // 'edit' => Pages\EditPersonalAccessToken::route('/{record}/edit'),
        ];
    }
}
