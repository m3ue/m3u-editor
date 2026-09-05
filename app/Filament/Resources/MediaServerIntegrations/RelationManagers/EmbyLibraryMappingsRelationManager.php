<?php

namespace App\Filament\Resources\MediaServerIntegrations\RelationManagers;

use App\Models\Category;
use App\Models\CustomPlaylist;
use App\Models\EmbyLibraryMapping;
use App\Models\Group;
use App\Models\MediaServerIntegration;
use App\Services\EmbyManagedSetupService;
use App\Services\EmbyPublicationCatalogService;
use App\Services\MediaServerService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmbyLibraryMappingsRelationManager extends RelationManager
{
    protected static string $relationship = 'embyLibraryMappings';

    protected static ?string $title = 'Managed Libraries';

    /**
     * Cap on how many catalog items the "Preview" action renders into the
     * DOM. The full item list (used for the actual sync) can run into the
     * thousands for a large library, which is enough to crash the browser
     * once rendered as one big <pre> block — the revision hash and every
     * other field are still computed from the complete, untruncated catalog.
     */
    private const int PREVIEW_ITEM_LIMIT = 50;

    /**
     * Per-request memo for outputPathOptions(), keyed by destination mode
     * (and target library for "existing"). The same options are otherwise
     * recomputed on every options()/visible()/required() closure call for
     * output_path and its sibling callout during a single form render.
     *
     * @var array<string, array<string, string>>
     */
    private array $outputPathOptionsCache = [];

    /** @var array<string, array<string, string>> */
    private array $simpleBulkSourceDescriptionsCache = [];

    /** @var array<string, array<string, string>> */
    private array $simpleBulkSourceOptionsCache = [];

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $ownerRecord->isEmby()
            && $user?->canUseIntegrations()
            && ($user->isAdmin() || $ownerRecord->user_id === $user->id);
    }

    public static function getTabComponent(Model $ownerRecord, string $pageClass): Tab
    {
        return Tab::make(__('Managed Libraries'))
            ->badge($ownerRecord->embyLibraryMappings()->count())
            ->icon('heroicon-m-rectangle-stack');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Fieldset::make(__('Publish to Emby'))
                    ->schema([
                        ToggleButtons::make('publication_type')
                            ->label(__('What do you want to publish?'))
                            ->options([
                                'movies' => __('Movies'),
                                'tvshows' => __('TV shows'),
                            ])
                            ->icons([
                                'movies' => 'heroicon-o-film',
                                'tvshows' => 'heroicon-o-tv',
                            ])
                            ->default('movies')
                            ->required()
                            ->grouped()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('sources', []);
                                $set('publish_all', false);
                                $set('destination', null);
                                $set('new_library_name', null);
                            })
                            ->columnSpanFull(),
                        Toggle::make('publish_all')
                            ->label(__('Publish all eligible items as one source'))
                            ->helperText(fn (Get $get): string => array_key_exists(
                                'all:'.$get('publication_type'),
                                $this->simpleBulkSourceDescriptions($get('publication_type')),
                            )
                                ? __('Already published')
                                : __('Leave this off to create one managed mapping and subfolder per selected group or category.'))
                            ->default(false)
                            ->live()
                            ->disabled(fn (Get $get): bool => array_key_exists(
                                'all:'.$get('publication_type'),
                                $this->simpleBulkSourceDescriptions($get('publication_type')),
                            ))
                            ->afterStateUpdated(fn (Set $set) => $set('sources', []))
                            ->columnSpanFull(),
                        CheckboxList::make('sources')
                            ->label(fn (Get $get): string => $get('publication_type') === 'tvshows'
                                ? __('Series categories')
                                : __('Movie groups'))
                            ->options(fn (Get $get): array => $this->simpleBulkSourceOptions($get('publication_type')))
                            ->descriptions(fn (Get $get): array => $this->simpleBulkSourceDescriptions($get('publication_type')))
                            ->disableOptionWhen(fn (string $value, Get $get): bool => array_key_exists(
                                $value,
                                $this->simpleBulkSourceDescriptions($get('publication_type')),
                            ))
                            ->required(fn (Get $get): bool => ! $get('publish_all'))
                            ->visible(fn (Get $get): bool => ! $get('publish_all'))
                            ->searchable()
                            ->bulkToggleable()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('destination', null);
                                $set('new_library_name', null);
                            })
                            ->columnSpanFull(),
                        Select::make('destination')
                            ->label(__('Emby library'))
                            ->options(fn (Get $get): array => [
                                '__new__' => __('Create a new library'),
                            ] + $this->simpleLibraryOptionsForCollectionType($get('publication_type')))
                            ->required()
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if ($state !== '__new__') {
                                    $set('new_library_name', null);
                                }
                            })
                            ->columnSpanFull(),
                        TextInput::make('new_library_name')
                            ->label(__('Library name'))
                            ->required(fn (Get $get): bool => $get('destination') === '__new__')
                            ->visible(fn (Get $get): bool => $get('destination') === '__new__')
                            ->maxLength(255),
                    ])
                    ->visible(fn (?EmbyLibraryMapping $record): bool => $record === null)
                    ->columnSpanFull(),
                Fieldset::make(__('Emby library'))
                    ->schema([
                        Toggle::make('enabled')
                            ->label(__('Enabled'))
                            ->columnSpanFull()
                            ->default(true),
                        Grid::make(2)->schema([
                            ToggleButtons::make('destination_mode')
                                ->label(__('Destination'))
                                ->grouped()
                                ->options([
                                    'existing' => __('Use an existing Emby library'),
                                    'new' => __('Create a managed Emby library'),
                                ])
                                ->icons([
                                    'existing' => 'heroicon-s-check',
                                    'new' => 'heroicon-s-plus',
                                ])
                                ->colors([
                                    'existing' => 'primary',
                                    'new' => 'success',
                                ])
                                ->default('new')
                                ->afterStateHydrated(function (ToggleButtons $component, ?EmbyLibraryMapping $record): void {
                                    $component->state($record?->is_managed === false ? 'existing' : 'new');
                                })
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                                    $set('output_path', null);
                                    $set('target_library_id', null);
                                    $set('collection_type', null);

                                    if ($state === 'new') {
                                        $set('target_library_name', null);
                                    }

                                    if ($get('source_kind') === 'custom_playlist_group') {
                                        $set('source_label', null);
                                    }
                                })
                                ->helperText(__('Choose whether m3u-editor should use an existing library or create and manage a new one.'))
                                ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('Existing libraries keep their Emby name, type, and management settings.'))
                                ->columnSpanFull(),
                            Select::make('target_library_id')
                                ->label(__('Existing library'))
                                ->options(fn (): array => $this->libraryOptions())
                                ->visible(fn (Get $get): bool => $get('destination_mode') === 'existing')
                                ->required(fn (Get $get): bool => $get('destination_mode') === 'existing')
                                ->searchable()
                                ->columnSpanFull()
                                ->live()
                                ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                                    $library = $this->library($state);
                                    $set('output_path', null);
                                    if ($library) {
                                        $set('target_library_name', $library['name'] ?? null);
                                        $set('collection_type', $library['type'] ?? null);

                                        if ($get('source_kind') === 'custom_playlist_group') {
                                            $set('source_label', null);
                                        }

                                        $paths = array_keys($this->compatibleLibraryPathOptions($state));
                                        if (count($paths) === 1) {
                                            $set('output_path', $paths[0]);
                                        }
                                    }
                                })
                                ->helperText(__('Choose the Emby library that will receive the published files. Its name and type are set automatically.')),
                            TextInput::make('target_library_name')
                                ->label(__('Library name'))
                                ->visible(fn (Get $get): bool => $get('destination_mode') === 'new')
                                ->required(fn (Get $get): bool => $get('destination_mode') === 'new')
                                ->maxLength(255)
                                ->helperText(__('This is the name m3u-editor will use when it creates the Emby library.'))
                                ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('Choose a clear, unique name that does not conflict with another Emby library.')),
                            Select::make('collection_type')
                                ->label(__('Library type'))
                                ->options([
                                    'movies' => __('Movies'),
                                    'tvshows' => __('TV shows'),
                                ])
                                ->visible(fn (Get $get): bool => $get('destination_mode') === 'new')
                                ->required(fn (Get $get): bool => $get('destination_mode') === 'new')
                                ->live()
                                ->afterStateUpdated(function (Set $set, Get $get): void {
                                    if ($get('source_kind') === 'custom_playlist_group') {
                                        $set('source_label', null);
                                    }
                                }),
                            Callout::make()
                                ->info()
                                ->description(__('The Emby companion writes STRM and NFO files to this destination. m3u-editor then creates and manages the Emby library.'))
                                ->visible(fn (Get $get): bool => $get('destination_mode') === 'new')
                                ->columnSpanFull(),
                            Select::make('output_path')
                                ->label(fn (Get $get): string => $get('destination_mode') === 'existing'
                                    ? __('Compatible library path')
                                    : __('Companion output path'))
                                ->options(fn (Get $get): array => $this->outputPathOptions($get))
                                ->visible(fn (Get $get): bool => $this->outputPathPickerIsVisible($get))
                                ->required(fn (Get $get): bool => $this->outputPathPickerIsVisible($get))
                                ->searchable()
                                ->columnSpanFull()
                                ->helperText(fn (Get $get): string => $get('destination_mode') === 'existing'
                                    ? __('Only Emby library paths inside a companion-confirmed writable root are available.')
                                    : __('The companion confirmed that it can write managed files to these destinations.'))
                                ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('The companion writes STRM and NFO files here; m3u-editor never resolves this path on its own host.')),
                            Callout::make()
                                ->warning()
                                ->description(fn (Get $get): string => $get('destination_mode') === 'existing'
                                    ? __('No compatible writable destination is available. Register a writable root in the Emby companion, then refresh this integration.')
                                    : __('No companion writable destination is available. Register a writable root in the Emby companion, then refresh this integration.'))
                                ->visible(fn (Get $get): bool => $this->destinationIsUnavailable($get))
                                ->columnSpanFull(),
                        ]),
                    ])
                    ->visible(fn (?EmbyLibraryMapping $record): bool => $record !== null)
                    ->columnSpanFull(),
                Fieldset::make(__('Source'))
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('source_kind')
                                ->label(__('Source type'))
                                ->options([
                                    'vod_group' => __('VOD group'),
                                    'series_category' => __('Series category'),
                                    'custom_playlist_group' => __('Custom playlist group'),
                                    'all' => __('All eligible items'),
                                ])
                                ->required()
                                ->helperText(__('Choose the m3u-editor content to publish into this Emby library.'))
                                ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('The source controls which eligible movies or TV shows are included.'))
                                ->live()
                                ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                                    $set('source_identifier', $state === 'all' ? '*' : null);
                                    $set('source_label', $state === 'all' ? __('All eligible items') : null);

                                    // collection_type is owned by the selected existing library
                                    // (see target_library_id's afterStateUpdated) once destination_mode
                                    // is "existing" — guessing it from source_kind here would silently
                                    // desync "Mapped group"'s options from the library actually chosen.
                                    if ($get('destination_mode') !== 'existing') {
                                        $set('collection_type', match ($state) {
                                            'vod_group' => 'movies',
                                            'series_category' => 'tvshows',
                                            default => null,
                                        });
                                    }
                                }),
                            Select::make('source_identifier')
                                ->label(__('Source'))
                                ->required()
                                ->helperText(__('Choose the specific source whose eligible items should be published.'))
                                ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('Only content owned by this integration user is available.'))
                                ->searchable()
                                ->live()
                                // Async search rather than a static options() list: vod_group and
                                // series_category can each span thousands of rows across a user's
                                // playlists, so loading them all upfront doesn't scale. The search
                                // results (and the option label shown once a value is selected) also
                                // append the owning playlist's name for vod_group/series_category —
                                // group/category names collide across playlists constantly, and
                                // without it there's no way to tell which playlist's "Action" you're
                                // actually picking. This is presentation-only: the raw, unsuffixed
                                // name is still what gets written to source_label below, since
                                // that's matched verbatim against channels.group/categories.name by
                                // EmbyPublicationCatalogService.
                                ->getSearchResultsUsing(fn (Get $get, string $search): array => $this->sourceSearchOptions($get('source_kind'), $search))
                                ->getOptionLabelUsing(fn (Get $get, ?string $state): ?string => $state === null
                                    ? null
                                    : $this->sourceSearchOptions($get('source_kind'), '', $state)[$state] ?? null)
                                ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                                    // For custom_playlist_group, sourceOptions() labels are the
                                    // CustomPlaylist's own name — never a valid source_label value
                                    // (that only ever comes from sourceLabelOptions(), which also
                                    // needs collection_type to know which groups are eligible).
                                    // Setting it here would populate "Mapped group" with a value
                                    // that's guaranteed invalid until collection_type is chosen too.
                                    if ($get('source_kind') === 'custom_playlist_group') {
                                        $set('source_label', null);

                                        return;
                                    }

                                    $set('source_label', $this->sourceOptions($get('source_kind'))[$state] ?? null);
                                }),
                            Select::make('source_label')
                                ->label(__('Mapped group'))
                                ->options(fn (Get $get): array => $this->sourceLabelOptions(
                                    $get('source_kind'),
                                    $get('source_identifier'),
                                    $get('collection_type'),
                                ))
                                // Only custom_playlist_group needs a second-level pick here: the
                                // "Source" select above chose the CustomPlaylist itself, and this
                                // field is where the specific group/category inside it is chosen —
                                // it's also the actual value the catalog matches items against for
                                // that source kind (see EmbyPublicationCatalogService). For every
                                // other source kind, source_identifier already uniquely identifies
                                // the group/category, and source_label is auto-populated from it
                                // (afterStateUpdated above) with the single matching option — so
                                // it's disabled rather than hidden: still visible for transparency
                                // and still validated/submitted, just not something the user needs
                                // to (or can) redundantly re-pick.
                                ->disabled(fn (Get $get): bool => $get('source_kind') !== 'custom_playlist_group' || ! $get('collection_type'))
                                ->dehydrated()
                                ->required()
                                ->helperText(function (Get $get): string {
                                    if ($get('source_kind') !== 'custom_playlist_group') {
                                        return __('Automatically set from the source selected above.');
                                    }

                                    if (! $get('collection_type')) {
                                        return __('Choose a library type first.');
                                    }

                                    if ($this->sourceLabelOptions($get('source_kind'), $get('source_identifier'), $get('collection_type')) === []) {
                                        return $get('collection_type') === 'movies'
                                            ? __('This custom playlist has no VOD groups available to publish as movies.')
                                            : __('This custom playlist has no series categories available to publish as TV shows.');
                                    }

                                    return __('Choose the specific group or category within the custom playlist to publish.');
                                })
                                ->searchable(),
                        ]),
                    ])
                    ->visible(fn (?EmbyLibraryMapping $record): bool => $record !== null)
                    ->columnSpanFull(),
                Fieldset::make(__('Publishing options'))
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('options.naming')
                                ->label(__('Naming'))
                                ->options([
                                    'media-year' => __('Title and year'),
                                    'title' => __('Title only'),
                                ])
                                ->default('media-year')
                                ->required()
                                ->helperText(__('Choose how published files are named.'))
                                ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('Including the year helps Emby match titles that share the same name.')),
                            Select::make('options.cleanup')
                                ->label(__('Cleanup'))
                                ->options([
                                    'replace' => __('Replace stale managed files'),
                                    'keep' => __('Keep stale managed files'),
                                    'disabled' => __('Do not clean up files'),
                                ])
                                ->default('replace')
                                ->required()
                                ->helperText(__('Choose what happens to managed files that are no longer in this source.'))
                                ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('Cleanup affects only files managed for this mapping.')),
                            Toggle::make('options.nfo')
                                ->label(__('Publish local NFO'))
                                ->default(true)
                                ->helperText(__('Write local metadata files alongside each STRM file.'))
                                ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('NFO files help Emby identify titles and retain m3u-editor metadata.')),
                            Toggle::make('options.versions')
                                ->label(__('Publish visible versions'))
                                ->default(true)
                                ->helperText(__('Keep distinct visible versions when the source contains multiple variants.'))
                                ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('Versions let Emby present multiple streams for the same title.')),
                            Toggle::make('options.refresh')
                                ->label(__('Refresh Emby after successful sync'))
                                ->columnSpanFull()
                                ->default(true)
                                ->helperText(__('Ask Emby to scan the library after the companion finishes writing files.'))
                                ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('Disable this only if Emby scans the destination on its own schedule.')),
                        ]),
                    ])
                    ->visible(fn (?EmbyLibraryMapping $record): bool => $record !== null)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('source_label')
            ->columns([
                TextColumn::make('source_label')
                    ->label(__('Source'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('target_library_name')
                    ->label(__('Emby library'))
                    ->searchable(),
                TextColumn::make('collection_type')
                    ->label(__('Type'))
                    ->badge(),
                TextColumn::make('output_path')
                    ->label(__('Output path'))
                    ->limit(40)
                    ->tooltip(fn (EmbyLibraryMapping $record): string => $record->output_path),
                ToggleColumn::make('enabled')
                    ->label(__('Enabled')),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'synced' => 'success',
                        'failed', 'drifted' => 'danger',
                        'pending', 'planned' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('last_applied_revision')
                    ->label(__('Applied revision'))
                    ->limit(12)
                    ->copyable()
                    ->placeholder(__('Not applied')),
                TextColumn::make('last_success_at')
                    ->label(__('Last success'))
                    ->since()
                    ->placeholder(__('Never')),
                IconColumn::make('is_managed')
                    ->label(__('Managed'))
                    ->boolean(),
                TextColumn::make('error_summary')
                    ->label(__('Last error'))
                    ->limit(50)
                    ->placeholder(__('None')),
            ])
            ->filters([])
            ->headerActions([
                Action::make('download_m3u_emby_plugin')
                    ->label(__('Get m3u-editor for Emby'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url('https://github.com/Serph91P/m3u-editor-for-emby')
                    ->openUrlInNewTab(),
                CreateAction::make()
                    ->label(__('Publish to Emby'))
                    ->modalSubmitActionLabel(__('Publish to Emby'))
                    ->using(fn (array $data): Model => $this->publish($data))
                    ->slideOver(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->button()
                    ->size('sm')
                    ->hiddenLabel(),
                EditAction::make()
                    ->button()
                    ->size('sm')
                    ->hiddenLabel()
                    ->mutateDataUsing(fn (array $data, EmbyLibraryMapping $record): array => $this->prepareMappingData($data, $record))
                    ->slideOver(),
                Action::make('reconcile')
                    ->label(__('Reconcile'))
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->button()
                    ->size('sm')
                    ->hiddenLabel()
                    ->action(fn (EmbyLibraryMapping $record) => $this->reconcile($record)),
                Action::make('preview')
                    ->label(__('Preview'))
                    ->icon('heroicon-o-eye')
                    ->modalHeading(__('Catalog plan preview'))
                    ->modalWidth('4xl')
                    ->modalContent(function (EmbyLibraryMapping $record) {
                        $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($record);
                        $itemsTotal = count($catalog['items']);

                        // Only the rendered JSON is capped — 'revision' was already
                        // hashed from, and the sync still uses, the complete item list.
                        $catalog['items'] = array_slice($catalog['items'], 0, self::PREVIEW_ITEM_LIMIT);

                        return view(
                            'filament.resources.media-server-integrations.relation-managers.emby-library-mapping-preview',
                            [
                                'catalog' => $catalog,
                                'itemsTotal' => $itemsTotal,
                                'itemsShown' => count($catalog['items']),
                            ],
                        );
                    })
                    ->button()
                    ->size('sm')
                    ->hiddenLabel()
                    ->slideOver()
                    ->modalSubmitAction(false),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function publish(array $data): EmbyLibraryMapping
    {
        try {
            return Cache::lock("emby-publish:{$this->ownerRecord->id}", 900)->block(1, function () use ($data): EmbyLibraryMapping {
                $sources = $this->resolveSimpleSources($data);
                $collectionType = $data['publication_type'] ?? null;
                if (! in_array($collectionType, EmbyLibraryMapping::COLLECTION_TYPES, true)) {
                    throw ValidationException::withMessages([
                        'destination' => __('Choose a compatible Emby library.'),
                    ]);
                }
                $existingSourceKeys = $this->ownerRecord->embyLibraryMappings()
                    ->where('collection_type', $collectionType)
                    ->get(['source_kind', 'source_identifier', 'source_label'])
                    ->map(fn (EmbyLibraryMapping $mapping): string => implode("\0", [
                        $mapping->source_kind,
                        $mapping->source_identifier,
                        $mapping->source_label,
                    ]))
                    ->flip();

                foreach ($sources as $source) {
                    if ($source['collection_type'] !== null && $source['collection_type'] !== $collectionType) {
                        throw ValidationException::withMessages([
                            'sources' => __('Choose sources that match the selected library type.'),
                        ]);
                    }
                    if ($source['kind'] === 'custom_playlist_group') {
                        $validLabels = $this->sourceLabelOptions(
                            $source['kind'],
                            $source['identifier'],
                            $collectionType,
                        );
                        if (! array_key_exists($source['label'], $validLabels)) {
                            throw ValidationException::withMessages([
                                'sources' => __('Choose an available group or category from this Custom Playlist.'),
                            ]);
                        }
                    }

                    $sourceKey = implode("\0", [$source['kind'], $source['identifier'], $source['label']]);
                    if ($existingSourceKeys->has($sourceKey)) {
                        throw ValidationException::withMessages([
                            'sources' => __('This source is already published to an Emby library of this type.'),
                        ]);
                    }
                }

                $setup = app(EmbyManagedSetupService::class)->setup($this->ownerRecord);
                if (! $setup['success']) {
                    throw ValidationException::withMessages([
                        'destination' => __($setup['message']),
                    ]);
                }

                $destination = $this->resolveSimpleDestination($data, $collectionType);

                $mapping = DB::transaction(function () use ($sources, $destination): EmbyLibraryMapping {
                    $created = collect();
                    // An existing library's root is never fully owned by M3U Editor, so its content
                    // must always be isolated to a source-specific subdirectory. Only a library this
                    // PR just created (managed) may use its root directly for a single "all" source.
                    $usesSourceSubdirectories = ! $destination['managed']
                        || count($sources) > 1
                        || $sources[0]['kind'] !== 'all';
                    foreach ($sources as $source) {
                        $mappingDestination = $destination;
                        if ($usesSourceSubdirectories) {
                            $mappingDestination['path'] = $this->managedSourcePath($destination['path'], $source);
                        }

                        $mapping = EmbyLibraryMapping::create([
                            'media_server_integration_id' => $this->ownerRecord->id,
                            'user_id' => $this->ownerRecord->user_id,
                            'enabled' => true,
                            'source_kind' => $source['kind'],
                            'source_identifier' => $source['identifier'],
                            'source_label' => $source['label'],
                            'target_library_id' => $mappingDestination['library_id'],
                            'target_library_name' => $mappingDestination['name'],
                            'collection_type' => $mappingDestination['collection_type'],
                            'output_path' => $mappingDestination['path'],
                            'is_managed' => $mappingDestination['managed'],
                            'options' => EmbyLibraryMapping::DEFAULT_OPTIONS,
                            'status' => 'idle',
                        ]);
                        $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($mapping);
                        $mapping->updateQuietly([
                            'last_planned_revision' => $catalog['revision'],
                            'status' => 'planned',
                            'status_summary' => __('Revision planned for companion sync.'),
                            'error_summary' => null,
                        ]);
                        $created->push($mapping->refresh());
                    }

                    return $created->firstOrFail();
                });
                $this->simpleBulkSourceDescriptionsCache = [];
                $this->simpleBulkSourceOptionsCache = [];

                return $mapping;
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'destination' => __('Another Emby publication is already in progress. Retry when it finishes.'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{kind: string, identifier: string, label: string, collection_type: string|null}>
     */
    private function resolveSimpleSources(array $data): array
    {
        $publicationType = is_string($data['publication_type'] ?? null)
            ? $data['publication_type']
            : null;
        if (($data['publish_all'] ?? false) === true) {
            if (! in_array($publicationType, EmbyLibraryMapping::COLLECTION_TYPES, true)) {
                throw ValidationException::withMessages([
                    'publication_type' => __('Choose movies or TV shows.'),
                ]);
            }

            return [$this->resolveSimpleSource(['source' => "all:{$publicationType}"])];
        }

        $sourceValues = is_array($data['sources'] ?? null) ? $data['sources'] : [];
        if ($sourceValues === [] && is_string($data['source'] ?? null)) {
            $sourceValues = [$data['source']];
        }
        $sourceValues = array_values(array_unique(array_filter(
            $sourceValues,
            fn (mixed $source): bool => is_string($source) && $source !== '',
        )));
        if ($sourceValues === []) {
            throw ValidationException::withMessages([
                'sources' => __('Choose at least one group or category.'),
            ]);
        }

        return array_map(
            fn (string $source): array => $this->resolveSimpleSource([...$data, 'source' => $source]),
            $sourceValues,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{kind: string, identifier: string, label: string, collection_type: string|null}
     */
    private function resolveSimpleSource(array $data): array
    {
        $source = is_string($data['source'] ?? null) ? $data['source'] : '';
        [$kind, $identifier, $encodedLabel] = array_pad(explode(':', $source, 3), 3, null);

        if ($kind === 'all' && in_array($identifier, EmbyLibraryMapping::COLLECTION_TYPES, true)) {
            return [
                'kind' => 'all',
                'identifier' => '*',
                'label' => __('All eligible items'),
                'collection_type' => $identifier,
            ];
        }

        $record = match ($kind) {
            'vod' => Group::query()
                ->where('user_id', $this->ownerRecord->user_id)
                ->where('type', 'vod')
                ->find($identifier),
            'series_category' => Category::query()
                ->where('user_id', $this->ownerRecord->user_id)
                ->find($identifier),
            'custom_playlist' => CustomPlaylist::query()
                ->where('user_id', $this->ownerRecord->user_id)
                ->find($identifier),
            default => null,
        };
        if ($record === null) {
            throw ValidationException::withMessages([
                'source' => __('Choose an available source.'),
            ]);
        }

        $sourceLabel = $record->name;
        $sourceKind = match ($kind) {
            'vod' => 'vod_group',
            'series_category' => 'series_category',
            default => 'custom_playlist_group',
        };
        $collectionType = match ($kind) {
            'vod' => 'movies',
            'series_category' => 'tvshows',
            default => null,
        };

        if ($kind === 'custom_playlist') {
            $sourceLabel = is_string($encodedLabel) && $encodedLabel !== ''
                ? rawurldecode($encodedLabel)
                : (is_string($data['custom_playlist_selection'] ?? null)
                    ? $data['custom_playlist_selection']
                    : '');
        }

        return [
            'kind' => $sourceKind,
            'identifier' => (string) $record->getKey(),
            'label' => $sourceLabel,
            'collection_type' => $collectionType,
        ];
    }

    /**
     * Build the "{kind}:{identifier}[:{urlencoded_label}]" key used to identify a source in
     * the "sources" CheckboxList. This is the single place that owns the encoding so the
     * options list, the "already published" descriptions, and resolveSimpleSource() can't drift.
     */
    private function encodeSimpleSourceKey(string $sourceKind, string $identifier, ?string $label = null): string
    {
        return match ($sourceKind) {
            'vod_group' => "vod:{$identifier}",
            'series_category' => "series_category:{$identifier}",
            default => "custom_playlist:{$identifier}:".rawurlencode((string) $label),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{library_id: string, name: string, collection_type: string, path: string, managed: bool}
     */
    private function resolveSimpleDestination(array $data, ?string $sourceCollectionType): array
    {
        $destinationValue = $data['destination'] ?? null;
        $destination = is_string($destinationValue) || is_int($destinationValue)
            ? (string) $destinationValue
            : '';
        if ($destination !== '__new__') {
            $library = $this->library($destination);
            $collectionType = $library['type'] ?? null;
            $paths = array_keys($this->compatibleLibraryPathOptions($destination));
            if ($library === null || ! is_string($library['name'] ?? null)
                || ! in_array($collectionType, EmbyLibraryMapping::COLLECTION_TYPES, true)
                || ($sourceCollectionType !== null && $sourceCollectionType !== $collectionType)
                || $paths === []) {
                throw ValidationException::withMessages([
                    'destination' => __('Choose a compatible Emby library.'),
                ]);
            }

            return [
                'library_id' => $destination,
                'name' => $library['name'],
                'collection_type' => $collectionType,
                'path' => $paths[0],
                'managed' => false,
            ];
        }

        $name = is_string($data['new_library_name'] ?? null) ? trim($data['new_library_name']) : '';
        $collectionType = $sourceCollectionType ?? ($data['new_library_type'] ?? null);
        $root = $this->ownerRecord->emby_managed_setup_root;
        if ($name === '' || mb_strlen($name) > 255
            || ! in_array($collectionType, EmbyLibraryMapping::COLLECTION_TYPES, true)
            || ! is_string($root) || ! MediaServerIntegration::isSafeWritablePath($root)) {
            throw ValidationException::withMessages([
                'destination' => __('Choose a valid new Emby library.'),
            ]);
        }

        $path = $this->managedLibraryPath($root, $name);
        if (! MediaServerIntegration::isSafeWritablePath($path)) {
            throw ValidationException::withMessages([
                'destination' => __('Choose a valid new Emby library.'),
            ]);
        }
        $result = MediaServerService::make($this->ownerRecord)->createLibrary(
            $name,
            $collectionType,
            [$path],
            false,
        );
        $libraryId = $result['library']['id'] ?? null;
        if (! $result['success'] || ! is_string($libraryId) || $libraryId === '') {
            throw ValidationException::withMessages([
                'destination' => __('Emby could not create the managed library. Retry after checking the companion version and administrator credential.'),
            ]);
        }

        return [
            'library_id' => $libraryId,
            'name' => $name,
            'collection_type' => $collectionType,
            'path' => $path,
            'managed' => true,
        ];
    }

    private function managedLibraryPath(string $root, string $name): string
    {
        $separator = str_contains($root, '\\') ? '\\' : '/';
        $directory = Str::slug($name);
        if ($directory === '') {
            $directory = 'library-'.substr(hash('sha256', $name), 0, 12);
        }

        return rtrim($root, '/\\').$separator.$directory;
    }

    /**
     * @param  array{kind: string, identifier: string, label: string, collection_type: string|null}  $source
     */
    private function managedSourcePath(string $root, array $source): string
    {
        $separator = str_contains($root, '\\') ? '\\' : '/';
        $slug = Str::slug($source['label']);
        if ($slug === '') {
            $slug = 'source';
        }
        $slug = substr($slug, 0, 80);
        $identity = implode("\0", [$source['kind'], $source['identifier'], $source['label']]);

        return rtrim($root, '/\\').$separator.$slug.'-'.substr(hash('sha256', $identity), 0, 10);
    }

    /** @return array<string, string> */
    private function simpleBulkSourceOptions(?string $collectionType): array
    {
        if ($collectionType !== null && array_key_exists($collectionType, $this->simpleBulkSourceOptionsCache)) {
            return $this->simpleBulkSourceOptionsCache[$collectionType];
        }

        $sourceKind = match ($collectionType) {
            'movies' => 'vod_group',
            'tvshows' => 'series_category',
            default => null,
        };
        if ($sourceKind === null) {
            return [];
        }

        $model = $sourceKind === 'vod_group' ? Group::class : Category::class;
        $query = $model::query()
            ->where('user_id', $this->ownerRecord->user_id)
            ->with('playlist:id,name')
            ->when($sourceKind === 'vod_group', fn ($builder) => $builder->where('type', 'vod'));
        $options = [];
        foreach ($query->orderBy('name')->get() as $record) {
            $options[$this->encodeSimpleSourceKey($sourceKind, (string) $record->id)] = $record->playlist?->name
                ? "{$record->name} ({$record->playlist->name})"
                : $record->name;
        }

        $customPlaylistRelations = $collectionType === 'movies'
            ? ['channels' => fn ($builder) => $builder
                ->where('channels.enabled', true)
                ->where('channels.is_vod', true)
                ->with('tags')]
            : ['series' => fn ($builder) => $builder
                ->where('series.enabled', true)
                ->with(['category', 'tags'])];
        $customPlaylists = CustomPlaylist::query()
            ->where('user_id', $this->ownerRecord->user_id)
            ->with($customPlaylistRelations)
            ->orderBy('name')
            ->get();

        foreach ($customPlaylists as $playlist) {
            $items = $collectionType === 'movies' ? $playlist->channels : $playlist->series;
            $tagType = $collectionType === 'movies' ? $playlist->uuid : $playlist->uuid.'-category';
            $labels = [];

            foreach ($items as $item) {
                $matchingTags = $item->tags->where('type', $tagType);
                if ($matchingTags->isNotEmpty()) {
                    foreach ($matchingTags as $tag) {
                        $label = $tag->getTranslation('name', 'en');
                        if ($label !== '') {
                            $labels[$label] = $label;
                        }
                    }

                    continue;
                }

                $label = $collectionType === 'movies' ? $item->group : $item->category?->name;
                if (is_string($label) && $label !== '') {
                    $labels[$label] = $label;
                }
            }

            natcasesort($labels);
            foreach ($labels as $label) {
                $key = $this->encodeSimpleSourceKey('custom_playlist_group', (string) $playlist->id, $label);
                $options[$key] = "{$playlist->name}: {$label}";
            }
        }

        return $this->simpleBulkSourceOptionsCache[$collectionType] = array_diff_key(
            $options,
            $this->simpleBulkSourceDescriptions($collectionType),
        );
    }

    /** @return array<string, string> */
    private function simpleBulkSourceDescriptions(?string $collectionType): array
    {
        if (! in_array($collectionType, EmbyLibraryMapping::COLLECTION_TYPES, true)) {
            return [];
        }

        if (array_key_exists($collectionType, $this->simpleBulkSourceDescriptionsCache)) {
            return $this->simpleBulkSourceDescriptionsCache[$collectionType];
        }

        $expectedKind = $collectionType === 'movies' ? 'vod_group' : 'series_category';

        return $this->simpleBulkSourceDescriptionsCache[$collectionType] = $this->ownerRecord->embyLibraryMappings()
            ->where('collection_type', $collectionType)
            ->whereIn('source_kind', [$expectedKind, 'custom_playlist_group', 'all'])
            ->get(['source_kind', 'source_identifier', 'source_label'])
            ->mapWithKeys(function (EmbyLibraryMapping $mapping) use ($collectionType): array {
                $key = $mapping->source_kind === 'all'
                    ? "all:{$collectionType}"
                    : $this->encodeSimpleSourceKey($mapping->source_kind, $mapping->source_identifier, $mapping->source_label);

                return [$key => __('Already published')];
            })
            ->all();
    }

    /** @return array<string, string> */
    private function simpleLibraryOptionsForCollectionType(?string $collectionType): array
    {
        $hasConfirmedWritableRoots = $this->ownerRecord->getEmbyPublisherWritablePaths() !== [];

        return collect($this->ownerRecord->available_libraries ?? [])
            ->filter(function (array $library) use ($collectionType, $hasConfirmedWritableRoots): bool {
                $type = $library['type'] ?? null;
                $hasSafePath = collect($library['paths'] ?? [])
                    ->contains(fn (mixed $path): bool => is_string($path)
                        && MediaServerIntegration::isSafeWritablePath($path));

                return in_array($type, EmbyLibraryMapping::COLLECTION_TYPES, true)
                    && ($collectionType === null || $type === $collectionType)
                    && ($hasConfirmedWritableRoots
                        ? $this->compatibleLibraryPathOptions((string) ($library['id'] ?? '')) !== []
                        : $hasSafePath);
            })
            ->mapWithKeys(fn (array $library): array => [
                (string) $library['id'] => $library['name'] ?? __('Unnamed library'),
            ])
            ->all();
    }

    /** @return array<string, string> */
    private function sourceOptions(?string $sourceKind): array
    {
        if ($sourceKind === 'all') {
            return ['*' => __('All eligible items')];
        }

        $query = match ($sourceKind) {
            'vod_group' => Group::query()
                ->where('user_id', $this->ownerRecord->user_id)
                ->where('type', 'vod')
                ->select(['id', 'name']),
            'series_category' => Category::query()
                ->where('user_id', $this->ownerRecord->user_id)
                ->select(['id', 'name']),
            'custom_playlist_group' => CustomPlaylist::query()
                ->where('user_id', $this->ownerRecord->user_id)
                ->select(['id', 'name']),
            default => null,
        };

        if ($query === null) {
            return [];
        }

        $options = [];
        foreach ($query->orderBy('name')->cursor() as $record) {
            $options[(string) $record->id] = $record->name;
        }

        return $options;
    }

    /**
     * Options for the "Source" select's async search (and, via
     * $onlyIdentifier, for resolving the label of an already-selected
     * value). Unlike sourceOptions(), labels for vod_group/series_category
     * are suffixed with the owning playlist's name — group/category names
     * routinely collide across a user's playlists, and this is the only
     * field where that ambiguity matters, so the suffix lives here rather
     * than in sourceOptions() (whose plain names still back source_label,
     * which EmbyPublicationCatalogService matches verbatim against
     * channels.group / categories.name).
     *
     * @return array<string, string>
     */
    private function sourceSearchOptions(?string $sourceKind, string $search, ?string $onlyIdentifier = null): array
    {
        if ($sourceKind === 'all') {
            return ['*' => __('All eligible items')];
        }

        if ($sourceKind === 'custom_playlist_group') {
            $query = CustomPlaylist::query()->where('user_id', $this->ownerRecord->user_id);

            if ($onlyIdentifier !== null) {
                $query->whereKey($onlyIdentifier);
            } elseif ($search !== '') {
                $query->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($search).'%']);
            }

            $options = [];
            foreach ($query->orderBy('name')->limit(50)->cursor() as $record) {
                $options[(string) $record->id] = $record->name;
            }

            return $options;
        }

        $model = match ($sourceKind) {
            'vod_group' => Group::class,
            'series_category' => Category::class,
            default => null,
        };

        if ($model === null) {
            return [];
        }

        $query = $model::query()
            ->where('user_id', $this->ownerRecord->user_id)
            ->with('playlist:id,name')
            ->when($sourceKind === 'vod_group', fn ($q) => $q->where('type', 'vod'));

        if ($onlyIdentifier !== null) {
            $query->whereKey($onlyIdentifier);
        } elseif ($search !== '') {
            $searchLower = strtolower($search);
            $query->where(function ($inner) use ($searchLower): void {
                $inner->whereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"])
                    ->orWhereHas('playlist', fn ($p) => $p->whereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"]));
            });
        }

        $options = [];
        foreach ($query->orderBy('name')->limit(50)->get() as $record) {
            $options[(string) $record->id] = $record->playlist?->name
                ? "{$record->name} ({$record->playlist->name})"
                : $record->name;
        }

        return $options;
    }

    /** @return array<string, string> */
    private function sourceLabelOptions(?string $sourceKind, ?string $sourceIdentifier, ?string $collectionType): array
    {
        if ($sourceKind !== 'custom_playlist_group') {
            $label = $this->sourceOptions($sourceKind)[$sourceIdentifier] ?? null;

            return $label === null ? [] : [$label => $label];
        }

        if (! in_array($collectionType, EmbyLibraryMapping::COLLECTION_TYPES, true)) {
            return [];
        }

        $customPlaylist = CustomPlaylist::query()
            ->where('user_id', $this->ownerRecord->user_id)
            ->find($sourceIdentifier);
        if (! $customPlaylist) {
            return [];
        }

        // Scoped by collection_type rather than unioning both: movies are
        // matched against VOD-channel groups and tvshows against series
        // categories (see EmbyPublicationCatalogService::buildMovies()/
        // buildSeries()), so a name valid for one is never a valid match for
        // the other — offering both together let you pick an option that
        // silently matched nothing at publish time.
        $groups = $collectionType === 'movies'
            ? $customPlaylist->filterableGroupsQuery(isVod: true)
            : $customPlaylist->filterableCategoriesQuery();

        $options = [];
        foreach ($groups->orderBy('name')->cursor() as $group) {
            $options[$group->name] = $group->name;
        }

        return $options;
    }

    /** @return array<string, string> */
    private function libraryOptions(): array
    {
        return collect($this->ownerRecord->available_libraries ?? [])
            ->filter(fn (array $library): bool => in_array($library['type'] ?? null, ['movies', 'tvshows'], true))
            ->mapWithKeys(fn (array $library): array => [
                (string) $library['id'] => ($library['name'] ?? __('Unnamed library')).' ('.($library['type'] ?? '').')',
            ])
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function library(?string $libraryId): ?array
    {
        if ($libraryId === null) {
            return null;
        }

        return collect($this->ownerRecord->available_libraries ?? [])
            ->first(fn (array $library): bool => (string) ($library['id'] ?? '') === $libraryId);
    }

    /** @return array<string, string> */
    private function compatibleLibraryPathOptions(?string $libraryId): array
    {
        $library = $this->library($libraryId);
        $writableRoots = $this->ownerRecord->getEmbyPublisherWritablePaths();
        $options = [];

        $libraryPaths = is_array($library['paths'] ?? null) ? $library['paths'] : [];
        foreach (array_slice($libraryPaths, 0, 50) as $path) {
            if (! is_string($path) || ! MediaServerIntegration::isSafeWritablePath($path)) {
                continue;
            }

            if (MediaServerIntegration::isPathWithinAnyWritableRoot($path, $writableRoots)) {
                $options[$path] = $path;
            }
        }

        return $options;
    }

    /** @return array<string, string> */
    private function writablePathOptions(): array
    {
        $paths = $this->ownerRecord->getEmbyPublisherWritablePaths();

        return array_combine($paths, $paths) ?: [];
    }

    /**
     * The output_path Select's option list for the current destination_mode,
     * memoized per request since options()/visible()/required() (and the
     * "unavailable" callout's visible()) would otherwise each recompute it.
     *
     * @return array<string, string>
     */
    private function outputPathOptions(Get $get): array
    {
        if ($get('destination_mode') === 'existing') {
            $libraryId = $get('target_library_id');

            return $this->outputPathOptionsCache['existing:'.$libraryId] ??= $this->compatibleLibraryPathOptions($libraryId);
        }

        return $this->outputPathOptionsCache['new'] ??= $this->writablePathOptions();
    }

    /**
     * Whether the output_path picker itself should be shown (and required).
     * In "existing" mode a single compatible path is auto-selected instead
     * of shown, so the picker only appears once there's a real choice.
     */
    private function outputPathPickerIsVisible(Get $get): bool
    {
        $options = $this->outputPathOptions($get);

        return $get('destination_mode') === 'existing'
            ? count($options) > 1
            : $options !== [];
    }

    /**
     * Whether to show the "no destination available" callout in place of
     * the output_path picker. Distinct from outputPathPickerIsVisible()'s
     * negation: in "existing" mode a single auto-selected path means
     * neither the picker nor this callout should show.
     */
    private function destinationIsUnavailable(Get $get): bool
    {
        if ($get('destination_mode') === 'new') {
            return $this->outputPathOptions($get) === [];
        }

        return $get('target_library_id') !== null && $this->outputPathOptions($get) === [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepareMappingData(array $data, ?EmbyLibraryMapping $record = null): array
    {
        $destinationMode = $data['destination_mode'] ?? null;

        if ($destinationMode === 'existing') {
            $libraryId = is_string($data['target_library_id'] ?? null) ? $data['target_library_id'] : null;
            $library = $this->library($libraryId);
            $libraryName = $library['name'] ?? null;
            $collectionType = $library['type'] ?? null;
            if ($library === null || ! is_string($libraryName) || trim($libraryName) === ''
                || mb_strlen($libraryName) > 255
                || ! in_array($collectionType, EmbyLibraryMapping::COLLECTION_TYPES, true)) {
                throw ValidationException::withMessages([
                    'target_library_id' => __('Choose an available Emby library.'),
                ]);
            }

            $compatiblePaths = array_keys($this->compatibleLibraryPathOptions($libraryId));
            if ($compatiblePaths === []) {
                throw ValidationException::withMessages([
                    'output_path' => __('Register a compatible writable root in the Emby companion, then refresh this integration.'),
                ]);
            }

            $outputPath = is_string($data['output_path'] ?? null) ? $data['output_path'] : null;
            if (! in_array($outputPath, $compatiblePaths, true)) {
                $isUnchangedStaleTarget = $record !== null
                    && $record->target_library_id === $libraryId;

                if (count($compatiblePaths) !== 1 || $isUnchangedStaleTarget) {
                    throw ValidationException::withMessages([
                        'output_path' => __('Choose an available compatible library path.'),
                    ]);
                }

                $outputPath = $compatiblePaths[0];
            }

            $data['target_library_name'] = $libraryName;
            $data['collection_type'] = $collectionType;
            $data['output_path'] = $outputPath;
            $data['is_managed'] = false;
        } elseif ($destinationMode === 'new') {
            $writablePaths = $this->ownerRecord->getEmbyPublisherWritablePaths();
            if (! in_array($data['output_path'] ?? null, $writablePaths, true)) {
                throw ValidationException::withMessages([
                    'output_path' => __('Choose a destination confirmed writable by the Emby companion.'),
                ]);
            }

            $data['target_library_id'] = $record?->is_managed ? $record->target_library_id : null;
            $data['is_managed'] = true;
        } else {
            throw ValidationException::withMessages([
                'destination_mode' => __('Choose an Emby library destination.'),
            ]);
        }

        unset($data['destination_mode']);

        return $data;
    }

    private function reconcile(EmbyLibraryMapping $mapping): void
    {
        $result = MediaServerService::make($this->ownerRecord)->createLibrary(
            $mapping->target_library_name,
            $mapping->collection_type,
            [$mapping->output_path],
            false,
            $mapping->target_library_id,
        );

        if (! $result['success']) {
            $error = EmbyLibraryMapping::redactSummary($result['message']);
            $mapping->updateQuietly([
                'status' => 'failed',
                'status_summary' => __('Reconcile failed.'),
                'error_summary' => $error,
            ]);
            Notification::make()
                ->danger()
                ->title(__('Managed library reconcile failed'))
                ->body($error)
                ->send();

            return;
        }

        $targetLibraryId = $result['library']['id'] ?? $mapping->target_library_id;
        if ($targetLibraryId !== $mapping->target_library_id) {
            $mapping->updateQuietly(['target_library_id' => $targetLibraryId]);
            $mapping->refresh();
        }

        if ($targetLibraryId === null) {
            $mapping->updateQuietly([
                'last_planned_revision' => null,
                'status' => 'pending',
                'status_summary' => __('Pending'),
                'error_summary' => null,
            ]);
            Notification::make()
                ->warning()
                ->title(__('Pending'))
                ->send();

            return;
        }

        // Emby's VirtualFolders API found the library by ID, but its name,
        // type, or paths no longer match this mapping — most likely someone
        // edited the mapping (or the library itself) after they were last in
        // sync. We don't auto-correct Emby's config here (renaming/moving a
        // library's paths can be destructive), so surface it clearly instead
        // of reporting a silent "planned" success.
        if ($result['drift'] ?? false) {
            $mapping->updateQuietly([
                'status' => 'drifted',
                'status_summary' => __('Emby library configuration differs from this mapping.'),
                'error_summary' => null,
            ]);
            Notification::make()
                ->warning()
                ->title(__('Managed library configuration drifted'))
                ->body(__('The existing Emby library\'s name, type, selected path, or ID no longer matches this mapping. Choose an available destination in the mapping or update the library in Emby.'))
                ->send();

            return;
        }

        $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($mapping);
        $mapping->updateQuietly([
            'last_planned_revision' => $catalog['revision'],
            'status' => 'planned',
            'status_summary' => __('Revision planned for companion sync.'),
            'error_summary' => null,
        ]);
        Notification::make()
            ->success()
            ->title(__('Managed library plan updated'))
            ->body(Str::limit($catalog['revision'], 12, ''))
            ->send();
    }
}
