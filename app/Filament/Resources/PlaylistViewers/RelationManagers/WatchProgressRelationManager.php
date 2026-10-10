<?php

namespace App\Filament\Resources\PlaylistViewers\RelationManagers;

use App\Forms\Components\TmdbSearchResults;
use App\Models\ViewerWatchProgress;
use App\Services\LogoService;
use App\Services\ManualWatchProgressService;
use App\Services\TmdbService;
use App\Tables\Columns\ProgressColumn;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

class WatchProgressRelationManager extends RelationManager
{
    protected static string $relationship = 'watchProgress';

    protected static ?string $title = 'Watch History';

    /**
     * Kept in the query string so a refresh lands back on the same tab.
     */
    #[Url(as: 'history')]
    public ?string $activeTab = null;

    /**
     * Accepts "h:mm:ss", "m:ss" or a plain number of minutes.
     */
    private const TIMESTAMP_PATTERN = '/^\d+(:[0-5]?\d){0,2}$/';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getTabs(): array
    {
        return [
            'live' => Tab::make(__('Live TV'))
                ->icon('heroicon-s-tv')
                ->badge(fn () => $this->ownerRecord->watchProgress()->where('content_type', 'live')->count())
                ->query(fn ($query) => $query->where('content_type', 'live')),

            'vod' => Tab::make(__('VOD'))
                ->icon('heroicon-s-film')
                ->badge(fn () => $this->ownerRecord->watchProgress()->where('content_type', 'vod')->count())
                ->query(fn ($query) => $query->where('content_type', 'vod')),

            'episode' => Tab::make(__('Series'))
                ->icon('heroicon-s-play')
                ->badge(fn () => $this->ownerRecord->watchProgress()->where('content_type', 'episode')->count())
                ->query(fn ($query) => $query->where('content_type', 'episode')),

            'dvr_recording' => Tab::make(__('DVR'))
                ->icon('heroicon-s-circle-stack')
                ->badge(fn () => $this->ownerRecord->watchProgress()->where('content_type', 'dvr_recording')->count())
                ->query(fn ($query) => $query->where('content_type', 'dvr_recording')),

            'aiostreams' => Tab::make(__('AIOStreams'))
                ->icon('heroicon-s-play')
                ->badge(fn () => $this->ownerRecord->watchProgress()->where('content_type', 'aiostreams')->count())
                ->query(fn ($query) => $query->where('content_type', 'aiostreams')),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->persistSortInSession()
            ->filtersTriggerAction(fn ($action) => $action->button()->label(__('Filters')))
            ->deferLoading()
            ->modifyQueryUsing(fn ($query) => match ($this->activeTab()) {
                'episode' => $query->with(['episode', 'episode.series.playlist', 'episode.playlist']),
                'dvr_recording' => $query->with(['dvrRecording', 'dvrRecording.dvrSetting.playlist', 'dvrRecording.user', 'dvrRecording.vodEpisode.series.playlist', 'dvrRecording.vodChannel.playlist']),
                'aiostreams' => $query,
                default => $query->with(['channel', 'channel.epgChannel', 'channel.playlist']),
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('last_watched_at', 'desc')
            ->columns([
                Split::make([
                    ImageColumn::make('poster')
                        ->label(__('Poster'))
                        ->state(fn (ViewerWatchProgress $record): ?string => $this->posterUrl($record))
                        // Art is a mix of 2:3 posters and 16:9 stills, so fit it in a
                        // bounding box at its natural aspect ratio instead of a fixed
                        // size the column's object-cover would crop.
                        ->imageWidth('auto')
                        ->imageHeight('auto')
                        ->extraImgAttributes(fn (): array => [
                            'style' => $this->activeTab() === 'live'
                                ? 'max-height:3rem; max-width:8rem; border-radius:6px;'
                                : 'min-width:140px; max-width:240px; max-height:210px; border-radius:6px;',
                        ])
                        ->checkFileExistence(false)
                        ->grow(false),

                    Stack::make([
                        TextColumn::make('display_title')
                            ->label(__('Title'))
                            ->state(fn (ViewerWatchProgress $record): string => $this->displayTitle($record))
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Large)
                            ->wrap()
                            ->searchable(query: fn (Builder $query, string $search): Builder => $this->applySearch($query, $search)),

                        TextColumn::make('subtitle')
                            ->label(__('Details'))
                            ->state(fn (ViewerWatchProgress $record): ?string => $this->subtitle($record))
                            ->color('gray'),

                        TextColumn::make('link_status')
                            ->label(__('Link Status'))
                            ->state(fn (ViewerWatchProgress $record): ?string => $record->isUnlinked() ? __('Not in playlist') : null)
                            ->badge()
                            ->color('warning')
                            ->icon('heroicon-s-link-slash')
                            ->tooltip(__('Added for a title that isn\'t in this playlist yet. Use "Relink Watch Progress" once it is.')),

                        TextColumn::make('plot')
                            ->label(__('Plot'))
                            ->state(fn (ViewerWatchProgress $record): ?string => $this->plot($record))
                            ->limit(180)
                            ->color('gray')
                            ->wrap()
                            ->visible(fn (): bool => in_array($this->activeTab(), ['vod', 'episode', 'aiostreams'], true)),

                        ProgressColumn::make('progress')
                            ->label(__('Progress'))
                            ->progress(fn (ViewerWatchProgress $record): int => $this->percent($record))
                            ->visible(fn (): bool => $this->activeTab() !== 'live'),

                        TextColumn::make('completed')
                            ->label(__('Position / Duration'))
                            ->state(fn (ViewerWatchProgress $record): string => $this->positionLabel($record))
                            ->icon(fn (ViewerWatchProgress $record): string => $record->completed ? 'heroicon-o-check-circle' : 'heroicon-o-clock')
                            ->iconColor(fn (ViewerWatchProgress $record): string => $record->completed ? 'success' : 'gray')
                            ->sortable()
                            ->visible(fn (): bool => $this->activeTab() !== 'live'),

                        TextColumn::make('watch_count')
                            ->label(fn (): string => $this->activeTab() === 'live' ? __('Tunes In') : __('Plays'))
                            ->formatStateUsing(fn (ViewerWatchProgress $record): string => $this->activeTab() === 'live'
                                ? trans_choice(':count tune-in|:count tune-ins', $record->watch_count, ['count' => $record->watch_count])
                                : trans_choice(':count play|:count plays', $record->watch_count, ['count' => $record->watch_count]))
                            ->color('gray')
                            ->sortable(),

                        TextColumn::make('last_watched_at')
                            ->label(__('Last Watched'))
                            ->dateTime()
                            ->since()
                            ->dateTimeTooltip()
                            ->prefix(__('Last watched').' ')
                            ->color('gray')
                            ->sortable(),
                    ])->space(1),
                ])->from('md'),
            ])
            ->recordActions([
                Action::make('play')
                    ->tooltip(__('Play'))
                    ->hidden(fn (ViewerWatchProgress $record): bool => $this->activeTab() === 'aiostreams' || $record->isUnlinked())
                    ->action(function (ViewerWatchProgress $record, $livewire): void {
                        $attributes = match ($this->activeTab()) {
                            'episode' => $record->episode?->getFloatingPlayerAttributes(),
                            'dvr_recording' => $record->dvrRecording?->getFloatingPlayerAttributes(),
                            default => $record->channel?->getFloatingPlayerAttributes(),
                        };

                        if ($attributes) {
                            $livewire->dispatch('openFloatingStream', $attributes);
                        }
                    })
                    ->icon('heroicon-s-play-circle')
                    ->button()
                    ->hiddenLabel(),
                $this->editProgressAction(),
                DeleteAction::make()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::AfterContent)
            ->headerActions([
                $this->addProgressAction(),
            ])
            ->filters([
                TernaryFilter::make('completed')
                    ->label(__('Completed'))
                    ->hidden(fn () => $this->activeTab() === 'live'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('markWatched')
                        ->label(__('Mark as watched'))
                        ->icon('heroicon-s-check-circle')
                        ->action(fn (Collection $records) => ViewerWatchProgress::whereKey($records->modelKeys())->update([
                            'completed' => true,
                            'position_seconds' => DB::raw('COALESCE(duration_seconds, position_seconds)'),
                        ]))
                        ->deselectRecordsAfterCompletion()
                        ->hidden(fn (): bool => $this->activeTab() === 'live'),
                    BulkAction::make('markUnwatched')
                        ->label(__('Mark as unwatched'))
                        ->icon('heroicon-s-arrow-uturn-left')
                        ->action(fn (Collection $records) => ViewerWatchProgress::whereKey($records->modelKeys())->update([
                            'completed' => false,
                            'position_seconds' => 0,
                        ]))
                        ->deselectRecordsAfterCompletion()
                        ->hidden(fn (): bool => $this->activeTab() === 'live'),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private function editProgressAction(): Action
    {
        return Action::make('editProgress')
            ->label(__('Edit'))
            ->tooltip(__('Edit progress'))
            ->icon('heroicon-s-pencil-square')
            ->button()
            ->hiddenLabel()
            ->size('sm')
            ->slideOver()
            ->modalWidth('md')
            ->modalHeading(fn (ViewerWatchProgress $record): string => $this->displayTitle($record))
            ->modalDescription(fn (ViewerWatchProgress $record): ?string => $this->subtitle($record))
            ->hidden(fn (): bool => $this->activeTab() === 'live')
            ->fillForm(fn (ViewerWatchProgress $record): array => [
                'position' => $this->formatSeconds($record->position_seconds),
                'duration' => $record->duration_seconds ? $this->formatSeconds($record->duration_seconds) : null,
                'completed' => $record->completed,
            ])
            ->schema($this->progressFields())
            ->action(function (ViewerWatchProgress $record, array $data): void {
                $record->update($this->progressAttributes($data));

                Notification::make()->success()->title(__('Watch progress updated'))->send();
            });
    }

    private function addProgressAction(): Action
    {
        $service = app(ManualWatchProgressService::class);

        return Action::make('addProgress')
            ->label(__('Add Progress'))
            ->icon('heroicon-s-plus')
            ->slideOver()
            ->modalWidth('4xl')
            ->modalHeading(__('Add Watch Progress'))
            ->modalDescription(__('Search TMDB for a movie or episode. Titles that aren\'t in this playlist are added as unlinked and can be relinked once the content is available.'))
            ->modalSubmitActionLabel(__('Add'))
            ->visible(fn (): bool => in_array($this->activeTab(), ['vod', 'episode'], true))
            ->disabled(fn (): bool => ! app(TmdbService::class)->isConfigured())
            ->tooltip(fn (): ?string => app(TmdbService::class)->isConfigured() ? null : __('Add a TMDB API key in Settings to search titles.'))
            ->fillForm(fn (): array => [
                'media_type' => $this->activeTab() === 'episode' ? 'tv' : 'movie',
                'season_number' => 1,
                'position' => '0:00',
                'completed' => false,
            ])
            ->schema(fn (): array => [
                Section::make(__('Search TMDB'))
                    ->schema([
                        ToggleButtons::make('media_type')
                            ->label(__('Type'))
                            ->options(['movie' => __('Movie'), 'tv' => __('Series')])
                            ->icons(['movie' => 'heroicon-s-film', 'tv' => 'heroicon-s-play'])
                            ->inline()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('search_results', []);
                                $set('tmdb_id', null);
                                $set('episode_number', null);
                                $set('duration', null);
                            }),
                        Grid::make(3)->schema([
                            TextInput::make('search_query')
                                ->label(__('Search Query'))
                                ->placeholder(__('Enter a title...'))
                                ->columnSpan(2),
                            TextInput::make('search_year')
                                ->label(__('Year (optional)'))
                                ->numeric()
                                ->minValue(1900)
                                ->maxValue(2100)
                                ->placeholder(__('e.g. 2024')),
                        ]),
                        Actions::make([
                            Action::make('searchTmdb')
                                ->label(__('Search TMDB'))
                                ->icon('heroicon-o-magnifying-glass')
                                ->action(function (Get $get, Set $set): void {
                                    $query = trim((string) $get('search_query'));
                                    if ($query === '') {
                                        Notification::make()->warning()->title(__('Please enter a search query'))->send();

                                        return;
                                    }

                                    $year = filled($get('search_year')) ? (int) $get('search_year') : null;
                                    $tmdb = app(TmdbService::class);

                                    $set('search_results', $get('media_type') === 'tv'
                                        ? $tmdb->searchTvSeriesManual($query, $year)
                                        : $tmdb->searchMovieManual($query, $year));
                                    $set('tmdb_id', null);
                                    $set('episode_number', null);
                                    $set('duration', null);
                                }),
                        ])->key('tmdbSearch')->fullWidth(),
                    ]),

                Section::make(__('Search Results'))
                    ->description(__('Click a result to select it'))
                    ->schema([
                        Hidden::make('tmdb_id')
                            ->live()
                            ->afterStateUpdated(function ($state, Get $get, Set $set) use ($service): void {
                                $set('episode_number', null);
                                $set('duration', null);

                                if ($state && $get('media_type') === 'movie') {
                                    $this->fillSuggestedDuration($service, $get, $set);
                                }
                            }),
                        TmdbSearchResults::make('search_results')
                            ->hiddenLabel()
                            ->type(fn (Get $get): string => $get('media_type') === 'tv' ? 'tv' : 'movie')
                            ->selectInto('tmdb_id')
                            ->default([])
                            ->dehydrated(false),
                    ]),

                Grid::make(2)
                    ->schema([
                        TextInput::make('season_number')
                            ->label(__('Season'))
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set) => $set('episode_number', null)),

                        Select::make('episode_number')
                            ->label(__('Episode'))
                            ->options(fn (Get $get): array => $get('tmdb_id') && $get('season_number') !== null && $get('season_number') !== ''
                                ? $service->episodeOptions((int) $get('tmdb_id'), (int) $get('season_number'))
                                : [])
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => $this->fillSuggestedDuration($service, $get, $set)),
                    ])
                    ->visible(fn (Get $get): bool => $get('media_type') === 'tv' && filled($get('tmdb_id'))),

                ...$this->progressFields(),
            ])
            ->action(function (array $data, Action $action) use ($service): void {
                if (blank($data['tmdb_id'] ?? null)) {
                    Notification::make()->warning()->title(__('Select a title from the search results'))->send();
                    $action->halt();
                }

                $isSeries = $data['media_type'] === 'tv';
                $attributes = $this->progressAttributes($data);
                $progress = $service->record(
                    viewer: $this->ownerRecord,
                    mediaType: $data['media_type'],
                    tmdbId: (int) $data['tmdb_id'],
                    seasonNumber: $isSeries ? (int) $data['season_number'] : null,
                    episodeNumber: $isSeries ? (int) $data['episode_number'] : null,
                    positionSeconds: $attributes['position_seconds'],
                    durationSeconds: $attributes['duration_seconds'],
                    completed: $attributes['completed'],
                );

                if (! $progress) {
                    Notification::make()->danger()->title(__('Could not load that title from TMDB'))->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('Watch progress added'))
                    ->body($progress->isUnlinked() ? __('Not in this playlist yet - added as unlinked.') : null)
                    ->send();
            });
    }

    /**
     * Position / duration / completed fields shared by the Add and Edit actions.
     *
     * @return array<int, mixed>
     */
    private function progressFields(): array
    {
        return [
            Grid::make(2)->schema([
                TextInput::make('position')
                    ->label(__('Position'))
                    ->placeholder('42:09')
                    ->helperText(__('h:mm:ss, m:ss or minutes'))
                    ->regex(self::TIMESTAMP_PATTERN)
                    ->required(),
                TextInput::make('duration')
                    ->label(__('Duration'))
                    ->placeholder('1:26:03')
                    ->regex(self::TIMESTAMP_PATTERN),
            ]),
            Toggle::make('completed')
                ->label(__('Completed')),
        ];
    }

    /**
     * Map the shared progress fields to model attributes. Position is clamped
     * to the duration when one is known.
     *
     * @return array{position_seconds: int, duration_seconds: ?int, completed: bool}
     */
    private function progressAttributes(array $data): array
    {
        $duration = filled($data['duration'] ?? null) ? $this->parseTimestamp($data['duration']) : null;
        $position = $this->parseTimestamp($data['position'] ?? '0');
        if ($duration) {
            $position = min($position, $duration);
        }

        return ['position_seconds' => $position, 'duration_seconds' => $duration, 'completed' => (bool) ($data['completed'] ?? false)];
    }

    private function fillSuggestedDuration(ManualWatchProgressService $service, Get $get, Set $set): void
    {
        if (! $get('tmdb_id')) {
            return;
        }

        $isSeries = $get('media_type') === 'tv';
        if ($isSeries && ! $get('episode_number')) {
            return;
        }

        $seconds = $service->suggestDurationSeconds(
            $this->ownerRecord,
            $get('media_type'),
            (int) $get('tmdb_id'),
            $isSeries ? (int) $get('season_number') : null,
            $isSeries ? (int) $get('episode_number') : null,
        );

        $set('duration', $seconds ? $this->formatSeconds($seconds) : null);
    }

    private function activeTab(): string
    {
        return $this->activeTab ?? 'live';
    }

    private function applySearch(Builder $query, string $search): Builder
    {
        $term = '%'.mb_strtolower($search).'%';
        $channelMatch = fn (Builder $q) => $q->whereRaw('LOWER(title) LIKE ?', [$term])->orWhereRaw('LOWER(name) LIKE ?', [$term]);

        return $query->where(fn (Builder $q) => match ($this->activeTab()) {
            'live' => $q->whereHas('channel', $channelMatch),
            'vod' => $q->whereHas('channel', $channelMatch)
                ->orWhereRaw('LOWER(viewer_watch_progress.title) LIKE ?', [$term]),
            'episode' => $q->whereHas('episode.series', fn (Builder $s) => $s->whereRaw('LOWER(name) LIKE ?', [$term]))
                ->orWhereHas('episode', fn (Builder $e) => $e->whereRaw('LOWER(title) LIKE ?', [$term]))
                ->orWhereRaw('LOWER(viewer_watch_progress.title) LIKE ?', [$term])
                ->orWhereRaw('LOWER(viewer_watch_progress.episode_title) LIKE ?', [$term]),
            'dvr_recording' => $q->whereHas('dvrRecording', fn (Builder $d) => $d->whereRaw('LOWER(title) LIKE ?', [$term])),
            'aiostreams' => $q->whereRaw('LOWER(title) LIKE ?', [$term])
                ->orWhereRaw('LOWER(aio_item_id) LIKE ?', [$term]),
            default => $q,
        });
    }

    private function posterUrl(ViewerWatchProgress $record): ?string
    {
        if ($record->content_type === 'aiostreams') {
            // Episode rows store the 16:9 still and some movies a backdrop; IMDb
            // ids (series id for episodes) map straight to a Metahub poster.
            return Str::startsWith((string) $record->aio_item_id, 'tt')
                ? 'https://images.metahub.space/poster/medium/'.Str::before($record->aio_item_id, ':').'/img'
                : $record->thumbnail_url;
        }

        if ($record->isUnlinked()) {
            return $record->thumbnail_url;
        }

        return match ($record->content_type) {
            'dvr_recording' => $this->dvrPosterUrl($record),
            'episode' => $record->episode?->series?->cover
                ? LogoService::getSeriesLogoUrl($record->episode->series)
                : LogoService::getEpisodeLogoUrl($record->episode),
            default => LogoService::getChannelLogoUrl($record->channel),
        };
    }

    /**
     * Series cover, then the recorded movie's logo, then the EPG programme icon.
     */
    private function dvrPosterUrl(ViewerWatchProgress $record): string
    {
        $recording = $record->dvrRecording;
        $series = $recording?->vodEpisode?->series;

        return match (true) {
            filled($series?->cover) => LogoService::getSeriesLogoUrl($series),
            filled($recording?->vodChannel) => LogoService::getChannelLogoUrl($recording->vodChannel),
            default => $recording?->epg_programme_icon ?? LogoService::getChannelLogoUrl(null),
        };
    }

    private function displayTitle(ViewerWatchProgress $record): string
    {
        if ($record->isUnlinked()) {
            return $record->title ?? __('Unknown');
        }

        return match ($record->content_type) {
            'episode' => $record->episode?->series?->name ?? $record->episode?->title ?? "Episode #{$record->stream_id}",
            'dvr_recording' => $record->dvrRecording?->display_title ?? "Recording #{$record->stream_id}",
            'aiostreams' => $record->title ?? $record->aio_item_id ?? __('Unknown'),
            default => $record->channel?->title ?? $record->channel?->name ?? "Stream #{$record->stream_id}",
        };
    }

    /**
     * "Movie · 2024 · tt0137523" / "Series · S05E03 · Episode title" line.
     */
    private function subtitle(ViewerWatchProgress $record): ?string
    {
        $parts = match ($record->content_type) {
            'vod' => [
                __('Movie'),
                $record->isUnlinked() ? $record->year : $record->channel?->year,
                $record->isUnlinked() ? null : $record->channel?->getImdbId(),
            ],
            'episode' => [
                __('Series'),
                $this->seasonEpisode(
                    $record->season_number ?? $record->episode?->season,
                    $record->episode_number ?? $record->episode?->episode_num,
                ),
                $record->isUnlinked() ? $record->episode_title : $record->episode?->title,
            ],
            'aiostreams' => [
                $record->season_number ? __('Series') : __('Movie'),
                $this->seasonEpisode($record->season_number, $record->episode_number),
                $record->episode_title,
            ],
            'dvr_recording' => [__('DVR Recording')],
            default => [$record->channel?->playlist?->name],
        };

        $line = collect($parts)->filter(fn ($part): bool => filled($part))->implode(' · ');

        return $line !== '' ? $line : null;
    }

    private function plot(ViewerWatchProgress $record): ?string
    {
        if ($record->content_type === 'aiostreams' || $record->isUnlinked()) {
            return $record->plot;
        }

        return match ($record->content_type) {
            'episode' => $record->episode?->info['plot'] ?? $record->episode?->series?->plot,
            'vod' => $record->channel?->info['plot'] ?? $record->channel?->info['description'] ?? null,
            default => null,
        };
    }

    private function seasonEpisode(?int $season, ?int $episode): ?string
    {
        if (! $season || ! $episode) {
            return null;
        }

        return sprintf('S%02dE%02d', $season, $episode);
    }

    private function percent(ViewerWatchProgress $record): int
    {
        if (! $record->duration_seconds || $record->duration_seconds <= 0) {
            return $record->completed ? 100 : 0;
        }

        return min(100, (int) round($record->position_seconds / $record->duration_seconds * 100));
    }

    private function positionLabel(ViewerWatchProgress $record): string
    {
        if (! $record->duration_seconds || $record->duration_seconds <= 0) {
            return $this->formatSeconds($record->position_seconds);
        }

        return "{$this->formatSeconds($record->position_seconds)} / {$this->formatSeconds($record->duration_seconds)} ({$this->percent($record)}%)";
    }

    private function parseTimestamp(string $value): int
    {
        $value = trim($value);
        if (! Str::contains($value, ':')) {
            return (int) $value * 60;
        }

        return collect(explode(':', $value))
            ->reduce(fn (int $seconds, string $part): int => $seconds * 60 + (int) $part, 0);
    }

    private function formatSeconds(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0:00';
        }
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $secs)
            : sprintf('%d:%02d', $minutes, $secs);
    }
}
