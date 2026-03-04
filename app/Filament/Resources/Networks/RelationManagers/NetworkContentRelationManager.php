<?php

namespace App\Filament\Resources\Networks\RelationManagers;

use App\Filament\Tables\NetworkEpisodesTable;
use App\Filament\Tables\NetworkMoviesTable;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Network;
use App\Models\NetworkContent;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

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
                return $action->button()->label('Sort');
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
     * @return array<int, \Filament\Tables\Columns\Column>
     */
    protected function getColumns(): array
    {
        return [
            TextColumn::make('sort_order')
                ->label('#')
                ->sortable()
                ->width('60px'),

            TextColumn::make('contentable_type')
                ->label('Type')
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
                ->label('Title')
                ->getStateUsing(fn (NetworkContent $record): string => $record->title)
                ->wrap()
                ->searchable(false),

            TextColumn::make('duration')
                ->label('Duration')
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

            TextColumn::make('weight')
                ->label('Weight')
                ->sortable()
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
                ->label('Add Movies')
                ->icon('heroicon-o-film')
                ->slideOver()
                ->visible(fn () => $playlistId !== null)
                ->schema([
                    ModalTableSelect::make('movies')
                        ->tableConfiguration(NetworkMoviesTable::class)
                        ->label('Select Movies')
                        ->multiple()
                        ->required()
                        ->helperText('Select movies to add to this network. Once added, you can sort them using drag and drop in the main table.')
                        ->tableArguments([
                            'playlist_id' => $playlistId,
                        ])
                        ->selectAction(
                            fn (Action $action) => $action
                                ->label('Select Movies from '.$mediaServerName)
                                ->modalHeading('Search and Select Movies')
                                ->modalSubmitActionLabel('Confirm selection')
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
                        ->title('Movies added')
                        ->body(count($movieIds).' movie(s) have been added to the network.')
                        ->send();
                })
                ->successNotificationTitle('Movies added successfully'),

            Action::make('addEpisodes')
                ->label('Add Episodes')
                ->icon('heroicon-o-play')
                ->slideOver()
                ->visible(fn () => $playlistId !== null)
                ->schema([
                    ModalTableSelect::make('episodes')
                        ->tableConfiguration(NetworkEpisodesTable::class)
                        ->label('Select Episodes')
                        ->multiple()
                        ->required()
                        ->helperText('Select episodes to add to this network. Once added, you can sort them using drag and drop in the main table.')
                        ->tableArguments([
                            'playlist_id' => $playlistId,
                        ])
                        ->selectAction(
                            fn (Action $action) => $action
                                ->label('Select Episodes from '.$mediaServerName)
                                ->modalHeading('Search and Select Episodes')
                                ->modalSubmitActionLabel('Confirm selection')
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
                        ->title('Episodes added')
                        ->body(count($episodeIds).' episode(s) have been added to the network.')
                        ->send();
                })
                ->successNotificationTitle('Episodes added successfully'),
        ];
    }

    /**
     * Get record actions for the table.
     *
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getRecordActions(): array
    {
        return [
            DeleteAction::make()
                ->icon('heroicon-o-x-circle')
                ->button()
                ->hiddenLabel(),
        ];
    }

    /**
     * Get toolbar actions for the table.
     *
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getToolbarActions(): array
    {
        return [
            BulkActionGroup::make([
                DeleteBulkAction::make(),
            ]),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('sort_order')
                ->label('Sort Order')
                ->numeric()
                ->default(0)
                ->required(),

            TextInput::make('weight')
                ->label('Weight (for shuffle)')
                ->numeric()
                ->default(1)
                ->minValue(1)
                ->helperText('Higher weight = more likely to appear when shuffling'),
        ]);
    }
}
