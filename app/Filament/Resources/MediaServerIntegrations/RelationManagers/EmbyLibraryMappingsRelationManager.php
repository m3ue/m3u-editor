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
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
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
                Section::make(__('Source'))
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
                                ->options(fn (Get $get): array => $this->sourceOptions($get('source_kind')))
                                ->required()
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(fn (Set $set, Get $get, ?string $state) => $set(
                                    'source_label',
                                    $this->sourceOptions($get('source_kind'))[$state] ?? null,
                                )),
                            Select::make('source_label')
                                ->label(__('Mapped group'))
                                ->options(fn (Get $get): array => $this->sourceLabelOptions(
                                    $get('source_kind'),
                                    $get('source_identifier'),
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
                                ->disabled(fn (Get $get): bool => $get('source_kind') !== 'custom_playlist_group')
                                ->dehydrated()
                                ->required()
                                ->helperText(fn (Get $get): string => $get('source_kind') === 'custom_playlist_group'
                                    ? __('Choose the specific group or category within the custom playlist to publish.')
                                    : __('Automatically set from the source selected above.'))
                                ->searchable(),
                            Select::make('collection_type')
                                ->label(__('Library type'))
                                ->options([
                                    'movies' => __('Movies'),
                                    'tvshows' => __('TV shows'),
                                ])
                                ->required(),
                        ]),
                    ])
                    ->columnSpanFull(),
                Section::make(__('Emby library'))
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
                Section::make(__('Publishing options'))
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
                    ]),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label(__('Preview'))
                    ->icon('heroicon-o-eye')
                    ->modalHeading(__('Exact catalog plan'))
                    ->modalDescription(fn (EmbyLibraryMapping $record): string => json_encode(
                        app(EmbyPublicationCatalogService::class)->buildMapping($record),
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    ) ?: '{}')
                    ->modalSubmitAction(false),
                Action::make('reconcile')
                    ->label(__('Reconcile'))
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(fn (EmbyLibraryMapping $record) => $this->reconcile($record)),
                EditAction::make(),
                DeleteAction::make(),
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

    /** @return array<string, string> */
    private function sourceLabelOptions(?string $sourceKind, ?string $sourceIdentifier): array
    {
        if ($sourceKind !== 'custom_playlist_group') {
            $label = $this->sourceOptions($sourceKind)[$sourceIdentifier] ?? null;

            return $label === null ? [] : [$label => $label];
        }

        $customPlaylist = CustomPlaylist::query()
            ->where('user_id', $this->ownerRecord->user_id)
            ->find($sourceIdentifier);
        if (! $customPlaylist) {
            return [];
        }

        $groups = $customPlaylist->filterableGroupsQuery(isVod: true)
            ->union($customPlaylist->filterableCategoriesQuery())
            ->orderBy('name')
            ->cursor();
        $options = [];
        foreach ($groups as $group) {
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
