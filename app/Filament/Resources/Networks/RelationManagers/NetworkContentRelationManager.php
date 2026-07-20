<?php

namespace App\Filament\Resources\Networks\RelationManagers;

use App\Filament\Tables\NetworkEpisodesTable;
use App\Filament\Tables\NetworkMoviesTable;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Network;
use App\Models\NetworkContent;
use App\Services\MediaServerService;
use App\Services\NetworkBroadcastService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class NetworkContentRelationManager extends RelationManager
{
    protected static string $relationship = 'networkContent';

    protected static ?string $title = 'Content';

    protected static ?string $recordTitleAttribute = 'id';

    /**
     * Get the playlist ID associated with this network's media server integration.
     */
    protected function getMediaServerPlaylistId(): ?int
    {
        /** @var Network $network */
        $network = $this->getOwnerRecord();

        return $network->mediaServerIntegration?->playlist_id;
    }

    /**
     * Get the media server integration name for display.
     */
    protected function getMediaServerName(): string
    {
        /** @var Network $network */
        $network = $this->getOwnerRecord();

        return $network->mediaServerIntegration?->name ?? 'Unknown';
    }

    public function table(Table $table): Table
    {
        $playlistId = $this->getMediaServerPlaylistId();
        $mediaServerName = $this->getMediaServerName();

        return $table
            ->reorderRecordsTriggerAction(function ($action) {
                return $action->button()->label(__('Sort'));
            })
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns($this->getColumns())
            ->headerActions($this->getHeaderActions($playlistId, $mediaServerName))
            ->emptyStateHeading(fn () => $playlistId
                ? 'No content added yet'
                : 'No media server linked')
            ->emptyStateDescription(fn () => $playlistId
                ? 'Add episodes or movies from your media server to this network.'
                : 'This network must be linked to a media server to add content.')
            ->emptyStateIcon(fn () => $playlistId
                ? 'heroicon-o-film'
                : 'heroicon-o-exclamation-triangle')
            ->recordActions($this->getRecordActions(), position: RecordActionsPosition::BeforeCells)
            ->toolbarActions($this->getToolbarActions());
    }

    /**
     * Return the columns used in the table.
     *
     * @return array<int, Column>
     */
    protected function getColumns(): array
    {
        return [
            TextColumn::make('sort_order')
                ->label(__('#'))
                ->sortable()
                ->width('60px'),

            TextColumn::make('contentable_type')
                ->label(__('Type'))
                ->formatStateUsing(fn (string $state): string => match ($state) {
                    'App\\Models\\Episode' => 'Episode',
                    'App\\Models\\Channel' => 'Movie',
                    default => 'Unknown',
                })
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'App\\Models\\Episode' => 'info',
                    'App\\Models\\Channel' => 'success',
                    default => 'gray',
                }),

            TextColumn::make('title')
                ->label(__('Title'))
                ->getStateUsing(fn (NetworkContent $record): string => $record->title)
                ->wrap()
                ->searchable(false),

            TextColumn::make('duration')
                ->label(__('Duration'))
                ->getStateUsing(function (NetworkContent $record): string {
                    $seconds = $record->duration_seconds;
                    if ($seconds <= 0) {
                        return '~30m (default)';
                    }
                    $hours = floor($seconds / 3600);
                    $minutes = floor(($seconds % 3600) / 60);

                    return $hours > 0
                        ? sprintf('%dh %dm', $hours, $minutes)
                        : sprintf('%dm', $minutes);
                }),

            TextInputColumn::make('weight')
                ->label(__('Weight'))
                ->type('number')
                ->rules(['required', 'integer', 'min:1'])
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),

            IconColumn::make('chain_id')
                ->label(__('Chained'))
                ->icon(fn (NetworkContent $record): string => $record->isChained() ? 'heroicon-o-link' : 'heroicon-o-minus')
                ->color(fn (NetworkContent $record): string => $record->isChained() ? 'warning' : 'gray')
                ->tooltip(fn (NetworkContent $record): ?string => $record->isChained()
                    ? 'Chained with '.($record->chainMembers()->count() - 1).' other item(s)'
                    : null)
                ->toggleable(isToggledHiddenByDefault: true),

            TextColumn::make('pin_day_of_week')
                ->label(__('Pin Day'))
                ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : '—')
                ->toggleable(isToggledHiddenByDefault: true),

            TextColumn::make('pin_time_of_day')
                ->label(__('Pin Time'))
                ->formatStateUsing(fn (?string $state): string => $state ?? '—')
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /**
     * Return header actions for the table.
     */
    protected function getHeaderActions(?int $playlistId, string $mediaServerName): array
    {
        /** @var Network $network */
        $network = $this->getOwnerRecord();

        return [
            Action::make('addMovies')
                ->label(__('Add Movies'))
                ->icon('heroicon-o-film')
                ->slideOver()
                ->visible(fn () => $playlistId !== null)
                ->schema([
                    ModalTableSelect::make('movies')
                        ->tableConfiguration(NetworkMoviesTable::class)
                        ->label(__('Select Movies'))
                        ->multiple()
                        ->required()
                        ->helperText(__('Select movies to add to this network. Once added, you can sort them using drag and drop in the main table.'))
                        ->tableArguments([
                            'playlist_id' => $playlistId,
                        ])
                        ->selectAction(
                            fn (Action $action) => $action
                                ->label('Select Movies from '.$mediaServerName)
                                ->modalHeading(__('Search and Select Movies'))
                                ->modalSubmitActionLabel(__('Confirm selection'))
                                ->button(),
                        )
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->title)
                        ->getOptionLabelsUsing(function (array $values): array {
                            return Channel::whereIn('id', $values)
                                ->pluck('title', 'id')
                                ->toArray();
                        }),
                ])
                ->action(function (array $data) use ($network): void {
                    $movieIds = $data['movies'] ?? [];

                    if (empty($movieIds)) {
                        return;
                    }

                    // Get the highest sort order
                    $maxSortOrder = $network->networkContent()->max('sort_order') ?? 0;

                    // Add selected movies to the network
                    foreach ($movieIds as $index => $movieId) {
                        $movie = Channel::find($movieId);
                        if ($movie) {
                            $network->networkContent()->create([
                                'contentable_type' => Channel::class,
                                'contentable_id' => $movie->id,
                                'sort_order' => $maxSortOrder + $index + 1,
                                'weight' => 1,
                            ]);
                        }
                    }

                    Notification::make()
                        ->success()
                        ->title(__('Movies added'))
                        ->body(count($movieIds).' movie(s) have been added to the network.')
                        ->send();
                })
                ->successNotificationTitle(__('Movies added successfully')),

            Action::make('addEpisodes')
                ->label(__('Add Episodes'))
                ->icon('heroicon-o-play')
                ->slideOver()
                ->visible(fn () => $playlistId !== null)
                ->schema([
                    ModalTableSelect::make('episodes')
                        ->tableConfiguration(NetworkEpisodesTable::class)
                        ->label(__('Select Episodes'))
                        ->multiple()
                        ->required()
                        ->helperText(__('Select episodes to add to this network. Once added, you can sort them using drag and drop in the main table.'))
                        ->tableArguments([
                            'playlist_id' => $playlistId,
                        ])
                        ->selectAction(
                            fn (Action $action) => $action
                                ->label('Select Episodes from '.$mediaServerName)
                                ->modalHeading(__('Search and Select Episodes'))
                                ->modalSubmitActionLabel(__('Confirm selection'))
                                ->button(),
                        )
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->title)
                        ->getOptionLabelsUsing(function (array $values): array {
                            return Episode::whereIn('id', $values)
                                ->pluck('title', 'id')
                                ->toArray();
                        }),
                ])
                ->action(function (array $data) use ($network): void {
                    $episodeIds = $data['episodes'] ?? [];

                    if (empty($episodeIds)) {
                        return;
                    }

                    // Get the highest sort order
                    $maxSortOrder = $network->networkContent()->max('sort_order') ?? 0;

                    // Add selected episodes to the network
                    foreach ($episodeIds as $index => $episodeId) {
                        $episode = Episode::find($episodeId);
                        if ($episode) {
                            $network->networkContent()->create([
                                'contentable_type' => Episode::class,
                                'contentable_id' => $episode->id,
                                'sort_order' => $maxSortOrder + $index + 1,
                                'weight' => 1,
                            ]);
                        }
                    }

                    Notification::make()
                        ->success()
                        ->title(__('Episodes added'))
                        ->body(count($episodeIds).' episode(s) have been added to the network.')
                        ->send();
                })
                ->successNotificationTitle(__('Episodes added successfully')),
        ];
    }

    /**
     * Get record actions for the table.
     *
     * @return array<int, Action>
     */
    protected function getRecordActions(): array
    {
        return [
            DeleteAction::make()
                ->icon('heroicon-o-x-circle')
                ->button()
                ->hiddenLabel(),

            $this->getTrackPreferencesAction(),

            EditAction::make()
                ->icon('heroicon-m-pencil-square')
                ->button()
                ->hiddenLabel(),
        ];
    }

    /**
     * Per-item audio/subtitle track override, sourced from that item's own real
     * streams (unlike the Network-level default, which is a single ISO code applied
     * across every item in the schedule — this is scoped to one known title, so it
     * can offer the operator its actual tracks instead of a generic language list).
     * Only shown for integrations that can list real stream metadata (Emby/Jellyfin/Plex);
     * Local/WebDAV have no MediaStreams-equivalent API to source options from.
     */
    protected function getTrackPreferencesAction(): Action
    {
        return Action::make('trackPreferences')
            ->label(__('Track Preferences'))
            ->icon('heroicon-o-language')
            ->button()
            ->modalHeading(fn (NetworkContent $record): string => __('Track Preferences: :title', ['title' => $record->title]))
            ->visible(fn (NetworkContent $record): bool => $this->supportsTrackPreferences($record))
            ->schema(function (NetworkContent $record): array {
                $tracks = $this->getAvailableTracksFor($record);

                return [
                    Select::make('preferred_audio_track')
                        ->label(__('Audio Track'))
                        ->options($this->trackOptions($tracks['audio']))
                        ->placeholder(__('Use network default'))
                        ->native(false)
                        ->nullable(),

                    Select::make('preferred_subtitle_track')
                        ->label(__('Subtitle Track'))
                        ->options($this->trackOptions($tracks['subtitle']))
                        ->placeholder(__('Use network default'))
                        ->helperText(__('Only shown if this title has embedded or sidecar subtitle tracks.'))
                        ->native(false)
                        ->nullable()
                        ->visible(fn () => ! empty($tracks['subtitle'])),
                ];
            })
            ->fillForm(fn (NetworkContent $record): array => [
                'preferred_audio_track' => $record->preferred_audio_track,
                'preferred_subtitle_track' => $record->preferred_subtitle_track,
            ])
            ->action(function (array $data, NetworkContent $record): void {
                // The subtitle Select is conditionally ->visible() based on whether this
                // item has any subtitle tracks; Filament omits hidden fields from $data
                // entirely, so this key may not exist at all (not just be empty/null).
                $record->update([
                    'preferred_audio_track' => ($data['preferred_audio_track'] ?? null) ?: null,
                    'preferred_subtitle_track' => ($data['preferred_subtitle_track'] ?? null) ?: null,
                ]);

                Notification::make()
                    ->success()
                    ->title(__('Track preferences updated'))
                    ->send();
            });
    }

    /**
     * Whether this content item's media server integration can list real stream
     * metadata to populate the track-preference selects. A cheap type check (no
     * API call) so it's safe to evaluate for every visible table row.
     */
    protected function supportsTrackPreferences(NetworkContent $record): bool
    {
        $integration = $record->network?->mediaServerIntegration;

        return $integration && ($integration->isEmby() || $integration->isJellyfin() || $integration->isPlex());
    }

    /**
     * Fetch this item's real audio/subtitle streams from its media server integration.
     * Only called when the modal actually opens (schema closures are lazy), not on
     * every table row render — unlike supportsTrackPreferences(), which must stay
     * API-call-free since it runs per row just to decide button visibility.
     *
     * @return array{audio: list<array{index: int, label: string, language: ?string}>, subtitle: list<array{index: int, label: string, language: ?string}>}
     */
    protected function getAvailableTracksFor(NetworkContent $record): array
    {
        $empty = ['audio' => [], 'subtitle' => []];

        $integration = $record->network?->mediaServerIntegration;
        $content = $record->contentable;

        if (! $integration || ! $content) {
            return $empty;
        }

        $itemId = app(NetworkBroadcastService::class)->getMediaServerItemId($content);
        if (! $itemId) {
            return $empty;
        }

        return MediaServerService::make($integration)->getAvailableTracks($itemId);
    }

    /**
     * @param  list<array{index: int, label: string, language: ?string}>  $tracks
     * @return array<string, string>
     */
    protected function trackOptions(array $tracks): array
    {
        return collect($tracks)
            ->mapWithKeys(fn (array $track): array => [(string) $track['index'] => $track['label']])
            ->toArray();
    }

    /**
     * Get toolbar actions for the table.
     *
     * @return array<int, Action>
     */
    protected function getToolbarActions(): array
    {
        /** @var Network $network */
        $network = $this->getOwnerRecord();

        return [
            BulkActionGroup::make([
                BulkAction::make('linkAsChain')
                    ->label(__('Link as Chain'))
                    ->icon('heroicon-o-link')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(__('Selected items will always play consecutively, in their current sort order, as one unit — most impactful in Shuffle mode.'))
                    ->action(function (Collection $records) use ($network): void {
                        if ($records->count() < 2) {
                            Notification::make()
                                ->warning()
                                ->title(__('Select at least 2 items to chain'))
                                ->send();

                            return;
                        }

                        $pinned = $records->filter(fn (NetworkContent $record): bool => $record->isPinned());

                        if ($pinned->isNotEmpty()) {
                            Notification::make()
                                ->danger()
                                ->title(__('Cannot chain pinned items'))
                                ->body(__('Unpin the selected item(s) first: ').$pinned->map(fn (NetworkContent $r) => $r->title)->implode(', '))
                                ->send();

                            return;
                        }

                        // Wrap the writes so a mid-loop failure can't leave chains half-reassigned.
                        DB::transaction(function () use ($records, $network): void {
                            $sorted = $records->sortBy('sort_order')->values();
                            $chainId = $sorted->first()->id;

                            // Chains being vacated by items joining this new chain —
                            // clean up any left with a single member afterward.
                            $vacatedChainIds = $records->pluck('chain_id')->filter()->unique()
                                ->reject(fn ($id) => $id === $chainId);

                            foreach ($sorted as $record) {
                                $record->update(['chain_id' => $chainId]);
                            }

                            foreach ($vacatedChainIds as $oldChainId) {
                                $remaining = NetworkContent::where('network_id', $network->id)
                                    ->where('chain_id', $oldChainId)
                                    ->get();

                                if ($remaining->count() === 1) {
                                    $remaining->first()->update(['chain_id' => null]);
                                }
                            }
                        });

                        Notification::make()
                            ->success()
                            ->title(__('Items chained'))
                            ->body($records->count().' item(s) will now play consecutively.')
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),

                BulkAction::make('unlinkChain')
                    ->label(__('Unlink Chain'))
                    ->icon('heroicon-o-link-slash')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(function (Collection $records) use ($network): void {
                        $chained = $records->filter(fn (NetworkContent $record): bool => $record->isChained());

                        if ($chained->isEmpty()) {
                            Notification::make()
                                ->warning()
                                ->title(__('No chained items selected'))
                                ->send();

                            return;
                        }

                        // Wrap the writes so a mid-loop failure can't leave a chain half-broken.
                        DB::transaction(function () use ($chained, $network): void {
                            $affectedChainIds = $chained->pluck('chain_id')->unique();

                            foreach ($chained as $record) {
                                $record->update(['chain_id' => null]);
                            }

                            // A chain of one left behind after unlinking is meaningless.
                            foreach ($affectedChainIds as $chainId) {
                                $remaining = NetworkContent::where('network_id', $network->id)
                                    ->where('chain_id', $chainId)
                                    ->get();

                                if ($remaining->count() === 1) {
                                    $remaining->first()->update(['chain_id' => null]);
                                }
                            }
                        });

                        Notification::make()
                            ->success()
                            ->title(__('Chain(s) unlinked'))
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),

                DeleteBulkAction::make(),
            ]),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('sort_order')
                ->label(__('Sort Order'))
                ->numeric()
                ->default(0)
                ->required(),

            TextInput::make('weight')
                ->label(__('Weight (for shuffle)'))
                ->numeric()
                ->default(1)
                ->minValue(1)
                ->helperText(__('Higher weight = more likely to appear when shuffling')),

            Select::make('pin_day_of_week')
                ->label(__('Pin Day of Week'))
                ->options([
                    'monday' => __('Monday'),
                    'tuesday' => __('Tuesday'),
                    'wednesday' => __('Wednesday'),
                    'thursday' => __('Thursday'),
                    'friday' => __('Friday'),
                    'saturday' => __('Saturday'),
                    'sunday' => __('Sunday'),
                ])
                ->nullable()
                ->placeholder(__('No pin'))
                ->helperText(__('Pin this content to a specific day each week'))
                ->rule('required_with:pin_time_of_day'),

            TextInput::make('pin_time_of_day')
                ->label(__('Pin Time (HH:MM)'))
                ->placeholder('20:00')
                ->regex('/^\d{2}:\d{2}$/')
                ->nullable()
                ->helperText(__('Time in 24-hour format, e.g. 20:00 for 8pm'))
                ->rule('required_with:pin_day_of_week'),
        ]);
    }
}
