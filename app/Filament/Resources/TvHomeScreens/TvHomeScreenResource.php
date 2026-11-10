<?php

namespace App\Filament\Resources\TvHomeScreens;

use App\Filament\Clusters\Devices\DevicesCluster;
use App\Filament\Resources\TvClientProfiles\TvClientProfileResource;
use App\Filament\Resources\TvHomeScreens\Pages\ListTvHomeScreens;
use App\Models\TvHomeScreen;
use App\Services\Sports\SportsService;
use App\Services\TvHomeScreenCategories;
use App\Support\TvClientSettings;
use App\Support\TvHomeScreenLayout;
use App\Traits\HasUserFiltering;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Per playlist M3U TV Home screens: the order of the app's sections,
 * their nav and Home toggles, and extra rows inside them. Lives in the
 * Devices cluster next to Settings Profiles and shares its gating.
 */
class TvHomeScreenResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = TvHomeScreen::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $cluster = DevicesCluster::class;

    // Slug is prefixed by the Devices cluster - the admin URL is /devices/home-screens.
    protected static ?string $slug = 'home-screens';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return TvClientProfileResource::canAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return TvClientProfileResource::isEnabled();
    }

    public static function getNavigationLabel(): string
    {
        return __('Home Screens');
    }

    public static function getModelLabel(): string
    {
        return __('Home Screen');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Home Screens');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make(__('Requires M3U TV :version or later', ['version' => TvHomeScreenLayout::MIN_APP_VERSION]))
                    ->description(__('Older versions ignore Home Screens and keep their own layout.'))
                    ->info()
                    ->columnSpanFull(),
                Select::make('target_key')
                    ->label(__('Playlist'))
                    ->helperText(fn (?TvHomeScreen $record): string => $record === null
                        ? __('Every login to this playlist gets this Home screen, including the Playlist Auths assigned to it. Each playlist can have one.')
                        : __('Every login to this playlist gets this Home screen. To use this layout for another playlist, duplicate it.'))
                    ->options(fn (?TvHomeScreen $record): array => static::availablePlaylistOptions($record))
                    ->formatStateUsing(fn (?TvHomeScreen $record, ?string $state): ?string => $record?->target_key ?? $state)
                    // Category rows belong to the playlist, so it's only picked on create.
                    ->disabled(fn (?TvHomeScreen $record): bool => $record !== null)
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => $set('sections', static::withoutCategories($get('sections'))))
                    ->columnSpanFull(),
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Textarea::make('description')
                    ->label(__('Description'))
                    ->rows(2)
                    ->maxLength(255)
                    ->columnSpanFull(),
                Select::make('mode')
                    ->label(__('Layout'))
                    ->options(static::modeOptions())
                    ->default(TvClientSettings::MODE_DEFAULT)
                    ->required()
                    ->native(false)
                    ->helperText(__('Default: the user can still rearrange Navigation & Home in the app. Enforced: the layout is locked.'))
                    ->columnSpanFull(),
                Section::make(__('Sections'))
                    ->description(__('Drag to reorder. Home, Search, Continue Watching, Notifications and Settings always stay where they are in the app.'))
                    ->compact()
                    ->columnSpanFull()
                    ->schema([
                        static::sectionsRepeater(),
                    ]),
            ]);
    }

    private static function sectionsRepeater(): Repeater
    {
        $labels = TvHomeScreenLayout::sectionLabels();

        return Repeater::make('sections')
            ->label(__('Sections'))
            ->hiddenLabel()
            ->default(fn (): array => static::keyedDefaultSections())
            ->addable(false)
            ->deletable(false)
            ->reorderable()
            ->collapsible()
            ->collapsed()
            ->itemLabel(fn (array $state): string => $labels[$state['section'] ?? ''] ?? (string) ($state['section'] ?? ''))
            ->schema([
                Hidden::make('section'),
                Grid::make(2)->schema([
                    Toggle::make('nav')
                        ->label(__('Show in nav'))
                        ->default(true),
                    Toggle::make('home')
                        ->label(__('Show on Home'))
                        ->default(true),
                ]),
                static::rowsRepeater(),
            ]);
    }

    /**
     * The default layout with every nested row under its own UUID key. The
     * sections Repeater keys its own items, but rows that arrive inside its
     * default keep list keys, which breaks drag and drop.
     *
     * @return list<array<string, mixed>>
     */
    private static function keyedDefaultSections(): array
    {
        return array_map(function (array $section): array {
            $section['rows'] = collect($section['rows'])
                ->mapWithKeys(fn (array $row): array => [(string) Str::uuid() => $row])
                ->all();

            return $section;
        }, TvHomeScreenLayout::defaultSections());
    }

    /**
     * The layout with every category row's category cleared, for when the
     * playlist changes: category ids belong to the playlist they came from.
     * Titles and dates stay, so only the category needs picking again.
     *
     * @param  array<array-key, mixed>|null  $sections
     * @return array<array-key, mixed>|null
     */
    private static function withoutCategories(?array $sections): ?array
    {
        foreach ($sections ?? [] as $sectionKey => $section) {
            foreach ($section['rows'] ?? [] as $rowKey => $row) {
                if (($row['kind'] ?? null) === TvHomeScreenLayout::ROW_CATEGORY) {
                    $sections[$sectionKey]['rows'][$rowKey]['params']['category_id'] = null;
                }
            }
        }

        return $sections;
    }

    private static function rowsRepeater(): Repeater
    {
        return Repeater::make('rows')
            ->label(__('Rows'))
            ->addActionLabel(__('Add row'))
            ->addable(fn (Get $get): bool => static::canAddRows($get('section')))
            ->reorderable()
            ->collapsible()
            ->deleteAction(fn (Action $action): Action => $action->visible(
                fn (array $arguments, Repeater $component): bool => ($component->getRawItemState($arguments['item'])['kind'] ?? null) !== TvHomeScreenLayout::ROW_DEFAULT
            ))
            ->itemLabel(fn (array $state): string => static::rowLabel($state))
            ->schema([
                Hidden::make('id')
                    ->default(fn (): string => (string) Str::uuid()),
                Hidden::make('kind')
                    ->default(fn (Get $get): ?string => TvHomeScreenLayout::customKindFor($get('../../section'))),
                TextInput::make('title')
                    ->label(__('Title'))
                    ->required()
                    ->maxLength(100)
                    ->visible(fn (Get $get): bool => static::isCustomRow($get('kind'))),
                Select::make('params.category_id')
                    ->label(__('Category'))
                    ->helperText(__('Categories and Dynamic Groups from the playlist above.'))
                    ->options(fn (Get $get): array => app(TvHomeScreenCategories::class)->for(
                        TvHomeScreen::resolveTargetKey($get('../../../../target_key'), auth()->id()),
                        TvHomeScreenLayout::categoryTypeFor($get('../../section')),
                    ))
                    ->searchable()
                    ->required()
                    ->visible(fn (Get $get): bool => $get('kind') === TvHomeScreenLayout::ROW_CATEGORY),
                Select::make('params.leagues')
                    ->label(__('Leagues'))
                    ->options(fn (): array => static::leagueOptions())
                    ->multiple()
                    ->searchable()
                    ->required()
                    ->visible(fn (Get $get): bool => $get('kind') === TvHomeScreenLayout::ROW_SPORTS_FIXTURES),
                Grid::make(2)
                    ->visible(fn (Get $get): bool => $get('kind') === TvHomeScreenLayout::ROW_SPORTS_FIXTURES)
                    ->schema([
                        Select::make('params.range')
                            ->label(__('Games from'))
                            ->options([
                                'today' => __('Today'),
                                'next_3_days' => __('The next 3 days'),
                            ])
                            ->default('today')
                            ->native(false)
                            ->required(),
                        TextInput::make('params.limit')
                            ->label(__('Games to show'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(TvHomeScreenLayout::SPORTS_MAX_LIMIT)
                            ->default(TvHomeScreenLayout::SPORTS_DEFAULT_LIMIT)
                            ->required(),
                    ]),
                Grid::make(2)
                    ->visible(fn (Get $get): bool => static::isCustomRow($get('kind')))
                    ->schema([
                        static::windowDatePicker('window.from')
                            ->label(__('Show from'))
                            ->helperText(__('Optional. Only the month and day count, so it repeats every year.'))
                            ->required(fn (Get $get): bool => filled($get('window.to'))),
                        static::windowDatePicker('window.to')
                            ->label(__('Show until'))
                            ->required(fn (Get $get): bool => filled($get('window.from'))),
                    ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function rowLabel(array $row): string
    {
        if (($row['kind'] ?? null) === TvHomeScreenLayout::ROW_DEFAULT) {
            return __('Built-in row');
        }

        return filled($row['title'] ?? null) ? $row['title'] : __('New row');
    }

    /**
     * @return array<string, string>
     */
    private static function modeOptions(): array
    {
        return [
            TvClientSettings::MODE_DEFAULT => __('Default (user can change)'),
            TvClientSettings::MODE_ENFORCED => __('Enforced (locked)'),
        ];
    }

    /**
     * Picks a day of the year. The picker holds a full date while editing
     * and reads and saves the layout's MM-DD through its format.
     */
    private static function windowDatePicker(string $name): DatePicker
    {
        return DatePicker::make($name)
            ->native(false)
            ->displayFormat('F j')
            ->closeOnDateSelection()
            ->format('m-d');
    }

    private static function isCustomRow(?string $kind): bool
    {
        return filled($kind) && $kind !== TvHomeScreenLayout::ROW_DEFAULT;
    }

    private static function canAddRows(?string $section): bool
    {
        return match (TvHomeScreenLayout::customKindFor($section)) {
            TvHomeScreenLayout::ROW_CATEGORY => true,
            TvHomeScreenLayout::ROW_SPORTS_FIXTURES => app(SportsService::class)->isEnabled(),
            default => false,
        };
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function leagueOptions(): array
    {
        $options = [];

        foreach (app(SportsService::class)->leagues() as $league) {
            $options[$league['sport_name']][$league['id']] = $league['name'];
        }

        return $options;
    }

    /**
     * The signed-in user's playlists of every type a login can resolve to
     * that don't have a Home screen yet, plus [$record]'s own, grouped by
     * type and keyed `<column>:<id>`.
     *
     * @return array<string, array<string, string>>
     */
    public static function availablePlaylistOptions(?TvHomeScreen $record): array
    {
        $options = [];
        $typeLabels = static::playlistTypeLabels(plural: true);

        foreach (TvHomeScreen::TARGET_COLUMNS as $column => $model) {
            $taken = TvHomeScreen::query()
                ->whereNotNull($column)
                ->when($record?->getKey(), fn ($query, $id) => $query->whereKeyNot($id))
                ->select($column);

            $playlists = $model::query()
                ->where('user_id', auth()->id())
                ->whereNotIn('id', $taken)
                ->orderBy('name')
                ->pluck('name', 'id');

            foreach ($playlists as $id => $name) {
                $options[$typeLabels[$column]]["{$column}:{$id}"] = (string) $name;
            }
        }

        return $options;
    }

    /**
     * @return array<string, string> column => label
     */
    private static function playlistTypeLabels(bool $plural = false): array
    {
        return $plural ? [
            'playlist_id' => __('Playlists'),
            'custom_playlist_id' => __('Custom Playlists'),
            'merged_playlist_id' => __('Merged Playlists'),
            'playlist_alias_id' => __('Playlist Aliases'),
        ] : [
            'playlist_id' => __('Playlist'),
            'custom_playlist_id' => __('Custom Playlist'),
            'merged_playlist_id' => __('Merged Playlist'),
            'playlist_alias_id' => __('Playlist Alias'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['playlist', 'customPlaylist', 'mergedPlaylist', 'playlistAlias']))
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('target')
                    ->label(__('Playlist'))
                    ->state(fn (TvHomeScreen $record): ?string => $record->target()?->name)
                    ->description(fn (TvHomeScreen $record): ?string => static::playlistTypeLabels()[strtok((string) $record->target_key, ':')] ?? null),
                TextColumn::make('mode')
                    ->label(__('Layout'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => static::modeOptions()[$state] ?? static::modeOptions()[TvClientSettings::MODE_DEFAULT])
                    ->color(fn (string $state): string => $state === TvClientSettings::MODE_ENFORCED ? 'warning' : 'gray'),
                TextColumn::make('custom_rows')
                    ->label(__('Custom Rows'))
                    ->state(fn (TvHomeScreen $record): int => $record->customRowCount())
                    ->badge()
                    ->color('gray'),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->button()->hiddenLabel()->size('sm'),
                EditAction::make()
                    ->slideOver()
                    ->button()->hiddenLabel()->size('sm'),
                static::duplicateAction()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Copies a Home screen to another playlist. Category ids belong to a
     * playlist, so category rows the other playlist doesn't have are left out.
     */
    private static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label(__('Duplicate to another playlist'))
            ->icon('heroicon-o-document-duplicate')
            ->schema([
                Select::make('target_key')
                    ->label(__('Playlist'))
                    ->options(fn (): array => static::availablePlaylistOptions(null))
                    ->searchable()
                    ->required(),
            ])
            ->action(function (TvHomeScreen $record, array $data): void {
                $target = TvHomeScreen::resolveTargetKey($data['target_key'] ?? null, auth()->id());

                if ($target === null) {
                    return;
                }

                $categories = app(TvHomeScreenCategories::class);
                $sections = $record->layout();
                $droppedRows = 0;

                foreach ($sections as &$section) {
                    $type = TvHomeScreenLayout::categoryTypeFor($section['section']);
                    $kept = array_values(array_filter(
                        $section['rows'],
                        fn (array $row): bool => $row['kind'] !== TvHomeScreenLayout::ROW_CATEGORY
                            || array_key_exists((string) ($row['params']['category_id'] ?? ''), $categories->for($target, $type)),
                    ));
                    $droppedRows += count($section['rows']) - count($kept);
                    $section['rows'] = $kept;
                }
                unset($section);

                TvHomeScreen::create([
                    'user_id' => $record->user_id,
                    'target_key' => $data['target_key'],
                    'name' => $record->name,
                    'description' => $record->description,
                    'mode' => $record->mode,
                    'sections' => $sections,
                ]);

                Notification::make()
                    ->success()
                    ->title(__('Home screen duplicated'))
                    ->body($droppedRows > 0
                        ? __(':count category rows were left out, since :playlist doesn\'t have those categories.', ['count' => $droppedRows, 'playlist' => $target->name])
                        : null)
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTvHomeScreens::route('/'),
        ];
    }
}
