<?php

namespace App\Filament\Resources\EpgChannels;

use App\Filament\Resources\EpgChannelResource\Pages;
use App\Filament\Resources\EpgChannels\Pages\ListEpgChannels;
use App\Jobs\EpgChannelFindAndReplace;
use App\Jobs\EpgChannelFindAndReplaceReset;
use App\Models\EpgChannel;
use App\Traits\HasUserFiltering;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class EpgChannelResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = EpgChannel::class;

    //    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'display_name'];
    }

    protected static ?string $label = 'EPG Channel';

    protected static ?string $pluralLabel = 'EPG Channels';

    protected static string|\UnitEnum|null $navigationGroup = 'EPG';

    public static function getNavigationSort(): ?int
    {
        return 5;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components(self::getForm());
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
            ->recordAction(null)
            ->columns([
                ImageColumn::make('logo')
                    ->label('Logo')
                    ->checkFileExistence(false)
                    ->size('inherit', 'inherit')
                    ->extraImgAttributes(fn ($record): array => [
                        'style' => 'height:2.5rem; width:auto; border-radius:4px;', // Live channel style
                    ])
                    ->getStateUsing(fn ($record) => $record->icon_custom ?? $record->icon)
                    ->toggleable(),
                TextInputColumn::make('display_name_custom')
                    ->label('Display Name')
                    ->rules(['min:0', 'max:255'])
                    ->tooltip(fn ($record) => $record->display_name)
                    ->placeholder(fn ($record) => $record->display_name)
                    ->searchable()
                    ->toggleable(),
                TextInputColumn::make('name_custom')
                    ->label('Name')
                    ->rules(['min:0', 'max:255'])
                    ->tooltip(fn ($record) => $record->name)
                    ->placeholder(fn ($record) => $record->name)
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('lang')
                    ->sortable()
                    ->toggleable()
                    ->searchable(),
                TextColumn::make('channel_id')
                    ->sortable()
                    ->toggleable()
                    ->searchable(),
                // WARNING! Slows table load quite a bit...
                // Tables\Columns\TextColumn::make('programmes_count')
                //     ->label('Programs')
                //     ->counts('programmes')
                //     ->sortable(),
                TextColumn::make('epg.name')
                    ->sortable()
                    ->numeric()
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('epg')
                    ->relationship('epg', 'name')
                    ->hidden(fn () => $relationId)
                    ->multiple()
                    ->preload()
                    ->searchable(),
            ])
            ->recordActions([
                EditAction::make()
                    ->slideOver()
                    ->button()
                    ->hiddenLabel(),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('find-replace')
                        ->label('Find & Replace')
                        ->schema([
                            Toggle::make('use_regex')
                                ->label('Use Regex')
                                ->live()
                                ->helperText('Use regex patterns to find and replace. If disabled, will use direct string comparison.')
                                ->default(true),
                            Select::make('column')
                                ->label('Column to modify')
                                ->options([
                                    'icon' => 'Channel Icon',
                                    'name' => 'Channel Name',
                                    'display_name' => 'Display Name',
                                ])
                                ->default('icon')
                                ->required()
                                ->columnSpan(1),
                            TextInput::make('find_replace')
                                ->label(fn (Get $get) => ! $get('use_regex') ? 'String to replace' : 'Pattern to replace')
                                ->required()
                                ->placeholder(
                                    fn (Get $get) => $get('use_regex')
                                        ? '^(US- |UK- |CA- )'
                                        : 'US -'
                                )->helperText(
                                    fn (Get $get) => ! $get('use_regex')
                                        ? 'This is the string you want to find and replace.'
                                        : 'This is the regex pattern you want to find. Make sure to use valid regex syntax.'
                                ),
                            TextInput::make('replace_with')
                                ->label('Replace with (optional)')
                                ->placeholder('Leave empty to remove'),

                        ])
                        ->action(function (Collection $records, array $data): void {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new EpgChannelFindAndReplace(
                                    user_id: auth()->id(), // The ID of the user who owns the content
                                    use_regex: $data['use_regex'] ?? true,
                                    column: $data['column'] ?? 'title',
                                    find_replace: $data['find_replace'] ?? null,
                                    replace_with: $data['replace_with'] ?? '',
                                    channels: $records
                                ));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Find & Replace started')
                                ->body('Find & Replace working in the background. You will be notified once the process is complete.')
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-magnifying-glass')
                        ->color('gray')
                        ->modalIcon('heroicon-o-magnifying-glass')
                        ->modalDescription('Select what you would like to find and replace in the selected epg channels.')
                        ->modalSubmitActionLabel('Replace now'),
                    BulkAction::make('find-replace-reset')
                        ->label('Undo Find & Replace')
                        ->schema([
                            Select::make('column')
                                ->label('Column to reset')
                                ->options([
                                    'icon' => 'Channel Icon',
                                    'name' => 'Channel Name',
                                    'display_name' => 'Display Name',
                                ])
                                ->default('icon')
                                ->required()
                                ->columnSpan(1),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new EpgChannelFindAndReplaceReset(
                                    user_id: auth()->id(), // The ID of the user who owns the content
                                    column: $data['column'] ?? 'title',
                                    channels: $records
                                ));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Find & Replace reset started')
                                ->body('Find & Replace reset working in the background. You will be notified once the process is complete.')
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->modalIcon('heroicon-o-arrow-uturn-left')
                        ->modalDescription('Reset Find & Replace results back to epg defaults for the selected epg channels. This will remove any custom values set in the selected column.')
                        ->modalSubmitActionLabel('Reset now'),
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
            'index' => ListEpgChannels::route('/'),
            // 'create' => Pages\CreateEpgChannel::route('/create'),
            // 'edit' => Pages\EditEpgChannel::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        return [
            TextInput::make('icon_custom')
                ->label('Icon')
                ->columnSpan(1)
                ->prefixIcon('heroicon-m-globe-alt')
                ->placeholder(fn ($record) => $record?->icon)
                ->helperText('Leave empty to use provider icon.')
                ->type('url'),
            TextInput::make('display_name_custom')
                ->label('Display Name')
                ->columnSpan(1)
                ->placeholder(fn ($record) => $record?->display_name)
                ->helperText('Leave empty to use provider display name.'),
            TextInput::make('name_custom')
                ->label('Name')
                ->columnSpan(2)
                ->placeholder(fn ($record) => $record?->name)
                ->helperText('Leave empty to use provider name.'),
        ];
    }
}
