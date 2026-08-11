<?php

namespace App\Filament\Resources\MediaServerIntegrations\RelationManagers;

use App\Models\Category;
use App\Models\CustomPlaylist;
use App\Models\EmbyLibraryMapping;
use App\Models\Group;
use App\Services\EmbyPublicationCatalogService;
use App\Services\MediaServerService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EmbyLibraryMappingsRelationManager extends RelationManager
{
    protected static string $relationship = 'embyLibraryMappings';

    protected static ?string $title = 'Managed Libraries';

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
                                ->live()
                                ->afterStateUpdated(function (Set $set, ?string $state): void {
                                    $set('source_identifier', $state === 'all' ? '*' : null);
                                    $set('source_label', $state === 'all' ? __('All eligible items') : null);
                                    $set('collection_type', match ($state) {
                                        'vod_group' => 'movies',
                                        'series_category' => 'tvshows',
                                        default => null,
                                    });
                                }),
                            Select::make('source_identifier')
                                ->label(__('Source'))
                                ->required()
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
                            Select::make('collection_type')
                                ->label(__('Library type'))
                                ->options([
                                    'movies' => __('Movies'),
                                    'tvshows' => __('TV shows'),
                                ])
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Set $set, Get $get): void {
                                    // A group/category chosen for one collection type is not
                                    // necessarily valid for the other (sourceLabelOptions() is
                                    // scoped by collection_type — see below), so it can't just
                                    // carry over silently.
                                    if ($get('source_kind') === 'custom_playlist_group') {
                                        $set('source_label', null);
                                    }
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
                    ->columnSpanFull(),
                Fieldset::make(__('Emby library'))
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('target_library_id')
                                ->label(__('Existing library'))
                                ->placeholder(__('Create a managed library'))
                                ->options(fn (): array => $this->libraryOptions())
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function (Set $set, ?string $state): void {
                                    if ($state === null) {
                                        return;
                                    }

                                    $library = collect($this->ownerRecord->available_libraries ?? [])
                                        ->firstWhere('id', $state);
                                    if ($library) {
                                        $set('target_library_name', $library['name'] ?? null);
                                        $set('collection_type', $library['type'] ?? null);
                                        $set('is_managed', false);
                                    }
                                }),
                            TextInput::make('target_library_name')
                                ->label(__('Library name'))
                                ->required()
                                ->maxLength(255),
                            Select::make('output_path')
                                ->label(__('Companion output path'))
                                ->options(fn (): array => $this->writablePathOptions())
                                ->required()
                                ->searchable()
                                ->columnSpanFull()
                                ->helperText(__('Only paths validated and advertised by m3u-editor for Emby are available.')),
                            Toggle::make('is_managed')
                                ->label(__('Create and manage this Emby library'))
                                ->default(true),
                            Toggle::make('enabled')
                                ->label(__('Enabled'))
                                ->default(true),
                        ]),
                    ])
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
                                ->required(),
                            Select::make('options.cleanup')
                                ->label(__('Cleanup'))
                                ->options([
                                    'replace' => __('Replace stale managed files'),
                                    'keep' => __('Keep stale managed files'),
                                    'disabled' => __('Do not clean up files'),
                                ])
                                ->default('replace')
                                ->required(),
                            Toggle::make('options.nfo')
                                ->label(__('Publish local NFO'))
                                ->default(true),
                            Toggle::make('options.versions')
                                ->label(__('Publish visible versions'))
                                ->default(true),
                            Toggle::make('options.refresh')
                                ->label(__('Refresh Emby after successful sync'))
                                ->columnSpanFull()
                                ->default(true),
                        ]),
                    ])
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
                    ->label(__('Create mapping'))
                    ->mutateDataUsing(fn (array $data): array => [
                        ...$data,
                        'media_server_integration_id' => $this->ownerRecord->id,
                        'user_id' => $this->ownerRecord->user_id,
                        'status' => 'idle',
                    ])
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
                    ->modalHeading(__('Exact catalog plan'))
                    ->modalWidth('4xl')
                    ->modalContent(fn (EmbyLibraryMapping $record) => view(
                        'filament.resources.media-server-integrations.relation-managers.emby-library-mapping-preview',
                        ['catalog' => app(EmbyPublicationCatalogService::class)->buildMapping($record)],
                    ))
                    ->button()
                    ->size('sm')
                    ->hiddenLabel()
                    ->modalSubmitAction(false),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
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

    /** @return array<string, string> */
    private function writablePathOptions(): array
    {
        return array_combine(
            $this->ownerRecord->getEmbyPublisherWritablePaths(),
            $this->ownerRecord->getEmbyPublisherWritablePaths(),
        ) ?: [];
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
                ->body(__('The existing Emby library\'s name, type, or paths no longer match this mapping. Update it manually in Emby, or delete it there to let reconcile recreate it.'))
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
