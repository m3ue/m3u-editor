<?php

namespace App\Filament\Resources\StreamFileSettings;

use App\Models\StreamFileSetting;
use App\Rules\CheckIfUrlOrLocalPath;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StreamFileSettingResource extends Resource
{
    protected static ?string $model = StreamFileSetting::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Playlist';

    protected static ?string $navigationLabel = 'Stream File Settings';

    protected static ?string $modelLabel = 'Stream File Setting';

    protected static ?string $pluralModelLabel = 'Stream File Settings';

    public static function getNavigationSort(): ?int
    {
        return 6;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Profile Name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('A descriptive name for this stream file setting profile'),

                Select::make('type')
                    ->label('Type')
                    ->options([
                        'series' => 'Series',
                        'vod' => 'VOD',
                    ])
                    ->required()
                    ->live()
                    ->helperText('Determines which path structure options are available and where this profile can be assigned'),

                Textarea::make('description')
                    ->label('Description')
                    ->columnSpanFull()
                    ->rows(2)
                    ->maxLength(255)
                    ->helperText('Optional description of this profile'),

                Toggle::make('enabled')
                    ->label('Enable .strm file generation')
                    ->default(true)
                    ->columnSpanFull()
                    ->live(),

                TextInput::make('location')
                    ->label('Sync Location')
                    ->rules([new CheckIfUrlOrLocalPath(localOnly: true, isDirectory: true)])
                    ->required()
                    ->columnSpanFull()
                    ->hidden(fn ($get) => ! $get('enabled'))
                    ->placeholder(fn ($get) => $get('type') === 'series' ? '/Series' : '/Movies')
                    ->helperText('Base directory path for synced .strm files'),

                ToggleButtons::make('path_structure')
                    ->label('Path structure (folders)')
                    ->live()
                    ->multiple()
                    ->grouped()
                    ->options(fn ($get) => $get('type') === 'series'
                        ? ['category' => 'Category', 'series' => 'Series', 'season' => 'Season']
                        : ['group' => 'Group', 'title' => 'Title']
                    )
                    ->default(fn ($get) => $get('type') === 'series'
                        ? ['category', 'series', 'season']
                        : ['group', 'title']
                    )
                    ->hidden(fn ($get) => ! $get('enabled')),

                Fieldset::make('Include Metadata')
                    ->columnSpanFull()
                    ->schema([
                        ToggleButtons::make('filename_metadata')
                            ->label('Filename metadata')
                            ->live()
                            ->inline()
                            ->multiple()
                            ->columnSpanFull()
                            ->options([
                                'year' => 'Year',
                                'tmdb_id' => 'TMDB ID',
                            ]),
                        ToggleButtons::make('tmdb_id_format')
                            ->label('TMDB ID format')
                            ->inline()
                            ->grouped()
                            ->live()
                            ->options([
                                'square' => '[square]',
                                'curly' => '{curly}',
                            ])
                            ->default('square')
                            ->hidden(fn ($get) => ! in_array('tmdb_id', $get('filename_metadata') ?? [])),
                    ])
                    ->hidden(fn ($get) => ! $get('enabled')),

                Fieldset::make('Filename Cleansing')
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('clean_special_chars')
                            ->label('Clean special characters')
                            ->helperText('Remove or replace special characters in filenames')
                            ->default(true)
                            ->inline(false),
                        Toggle::make('remove_consecutive_chars')
                            ->label('Remove consecutive replacement characters')
                            ->default(true)
                            ->inline(false),
                        ToggleButtons::make('replace_char')
                            ->label('Replace with')
                            ->live()
                            ->inline()
                            ->grouped()
                            ->columnSpanFull()
                            ->options([
                                'space' => 'Space',
                                'dash' => '-',
                                'underscore' => '_',
                                'period' => '.',
                                'remove' => 'Remove',
                            ])
                            ->default('space'),
                    ])
                    ->hidden(fn ($get) => ! $get('enabled')),

                Fieldset::make('Name Filtering')
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('name_filter_enabled')
                            ->label('Enable name filtering')
                            ->helperText('Remove specific words or symbols from folder and file names')
                            ->inline(false)
                            ->live(),
                        Forms\Components\TagsInput::make('name_filter_patterns')
                            ->label('Patterns to remove')
                            ->placeholder('Add pattern (e.g. "DE • " or "EN |")')
                            ->helperText('Enter words, symbols or prefixes to remove. Press Enter after each pattern.')
                            ->columnSpanFull()
                            ->hidden(fn ($get) => ! $get('name_filter_enabled')),
                    ])
                    ->hidden(fn ($get) => ! $get('enabled')),

                Fieldset::make('NFO File Generation')
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('generate_nfo')
                            ->label('Generate NFO files')
                            ->helperText(fn ($get) => $get('type') === 'series'
                                ? 'Create tvshow.nfo and episode.nfo files for Kodi, Emby, and Jellyfin compatibility'
                                : 'Create movie.nfo files for Kodi, Emby, and Jellyfin compatibility'
                            )
                            ->inline(false),
                    ])
                    ->hidden(fn ($get) => ! $get('enabled')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->persistSortInSession()
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->modifyQueryUsing(function ($query) {
                $query->withCount(['series', 'channels', 'groups', 'categories']);
            })
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->colors([
                        'primary' => 'series',
                        'success' => 'vod',
                    ])
                    ->sortable(),
                TextColumn::make('location')
                    ->label('Location')
                    ->limit(30)
                    ->toggleable(),
                ToggleColumn::make('enabled')
                    ->label('Enabled'),
                TextColumn::make('series_count')
                    ->label('Series')
                    ->counts('series')
                    ->toggleable(),
                TextColumn::make('channels_count')
                    ->label('VOD')
                    ->counts('channels')
                    ->toggleable(),
                TextColumn::make('groups_count')
                    ->label('Groups')
                    ->counts('groups')
                    ->toggleable(),
                TextColumn::make('categories_count')
                    ->label('Categories')
                    ->counts('categories')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'series' => 'Series',
                        'vod' => 'VOD',
                    ]),
            ])
            ->recordActions([
                Actions\DeleteAction::make()
                    ->button()->hiddenLabel()->size('sm'),
                Actions\EditAction::make()
                    ->slideOver()
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
            'index' => Pages\ListStreamFileSettings::route('/'),
        ];
    }
}
