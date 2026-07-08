<?php

namespace App\Traits;

use App\Models\Category;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Series;
use App\Services\TmdbService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Livewire handlers for the manual TMDB search modal.
 *
 * The `manual_tmdb_search` action (defined in VodResource/SeriesResource
 * table actions) renders the tmdb-search-results component, whose result
 * buttons call `applyTmdbSelection` on the hosting Livewire component.
 * Every page or relation manager that can mount that action must use this
 * trait, otherwise clicking a search result throws a Livewire
 * MethodNotFoundException.
 */
trait AppliesTmdbSelection
{
    public function applyTmdbSelection(int $tmdbId, string $type, ?int $recordId, string $recordType): void
    {
        try {
            if (! $recordId) {
                Log::error('Manual TMDB search: Record ID is null', [
                    'tmdb_id' => $tmdbId,
                    'type' => $type,
                    'recordType' => $recordType,
                ]);

                Notification::make()
                    ->danger()
                    ->title(__('Error'))
                    ->body(__('Could not determine the record to update. Please close the modal and try again.'))
                    ->send();

                return;
            }

            $tmdbService = app(TmdbService::class);

            if ($type === 'tv' && $recordType === 'series') {
                $series = Series::where('user_id', auth()->id())->find($recordId);
                if (! $series) {
                    Notification::make()
                        ->danger()
                        ->title(__('Error'))
                        ->body(__('Series record not found.'))
                        ->send();

                    return;
                }

                $this->applySeriesMetadata($tmdbService, $series, $tmdbId);
            } elseif ($type === 'movie' && $recordType === 'vod') {
                $vod = Channel::where('user_id', auth()->id())->find($recordId);
                if (! $vod) {
                    Notification::make()
                        ->danger()
                        ->title(__('Error'))
                        ->body(__('VOD record not found.'))
                        ->send();

                    return;
                }

                $this->applyVodMetadata($tmdbService, $vod, $tmdbId);
            } else {
                Notification::make()
                    ->danger()
                    ->title(__('Error'))
                    ->body(__('Failed to apply TMDB selection.'))
                    ->send();

                return;
            }
        } catch (\Throwable $e) {
            Log::error('Manual TMDB search: Error applying selection', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tmdb_id' => $tmdbId,
            ]);

            Notification::make()
                ->danger()
                ->title(__('Error'))
                ->body('An error occurred: '.$e->getMessage())
                ->send();
        }
    }

    /**
     * Fetch full metadata from TMDB and apply it to a VOD channel.
     */
    protected function applyVodMetadata(TmdbService $tmdbService, Channel $vod, int $tmdbId): void
    {
        $metadata = $tmdbService->applyMovieSelection($tmdbId);
        if (! $metadata) {
            Notification::make()
                ->danger()
                ->title(__('Error'))
                ->body(__('Failed to fetch TMDB data for this movie.'))
                ->send();

            return;
        }

        $info = $vod->info ?? [];
        $updateData = [
            'tmdb_id' => $metadata['tmdb_id'],
        ];

        if (! empty($metadata['imdb_id'])) {
            $updateData['imdb_id'] = $metadata['imdb_id'];
        }

        $info['tmdb_id'] = $metadata['tmdb_id'];
        if (! empty($metadata['imdb_id'])) {
            $info['imdb_id'] = $metadata['imdb_id'];
        }

        // Fetch full movie details to populate metadata
        $details = $tmdbService->getMovieDetails($tmdbId);
        if ($details) {
            if (! empty($details['imdb_id']) && empty($updateData['imdb_id'])) {
                $updateData['imdb_id'] = $details['imdb_id'];
                $info['imdb_id'] = $details['imdb_id'];
            }

            if (! empty($details['poster_url'])) {
                $info['cover_big'] = $details['poster_url'];
            }

            if (! empty($details['overview'])) {
                $info['plot'] = $details['overview'];
            }

            if (! empty($details['genres']) && (empty($info['genre']) || ($info['genre'] ?? '') === 'Uncategorized')) {
                $info['genre'] = $details['genres'];

                $primaryGenre = is_string($details['genres'])
                    ? explode(', ', $details['genres'])[0]
                    : (is_array($details['genres']) ? $details['genres'][0] : null);

                if ($primaryGenre && ($vod->group === 'Uncategorized' || $vod->group_internal === 'Uncategorized')) {
                    $group = Group::firstOrCreate(
                        [
                            'playlist_id' => $vod->playlist_id,
                            'name' => $primaryGenre,
                        ],
                        [
                            'name_internal' => $primaryGenre,
                            'user_id' => $vod->user_id,
                            'type' => 'vod',
                        ]
                    );
                    $updateData['group'] = $primaryGenre;
                    $updateData['group_internal'] = $primaryGenre;
                    $updateData['group_id'] = $group->id;
                }
            }

            if (! empty($details['release_date'])) {
                $info['release_date'] = $details['release_date'];
            }

            if (! empty($details['release_date']) && empty($vod->year)) {
                $updateData['year'] = substr($details['release_date'], 0, 4);
            }

            if (! empty($details['vote_average'])) {
                $info['rating'] = $details['vote_average'];
            }

            if (! empty($details['backdrop_url'])) {
                $info['backdrop_path'] = [$details['backdrop_url']];
            }

            if (! empty($details['cast'])) {
                $info['cast'] = is_array($details['cast']) ? implode(', ', $details['cast']) : $details['cast'];
            }

            if (! empty($details['director'])) {
                $info['director'] = is_array($details['director']) ? implode(', ', $details['director']) : $details['director'];
            }

            if (! empty($details['youtube_trailer'])) {
                $info['youtube_trailer'] = $details['youtube_trailer'];
            }

            if (! empty($details['runtime']) && (empty($info['duration_secs']) || ($info['duration_secs'] ?? 0) === 0)) {
                $runtimeMinutes = (int) $details['runtime'];
                $runtimeSeconds = $runtimeMinutes * 60;
                $info['duration_secs'] = $runtimeSeconds;
                $info['duration'] = gmdate('H:i:s', $runtimeSeconds);
                $info['episode_run_time'] = $runtimeMinutes;
            }
        }

        $updateData['info'] = $info;

        // Update logo from TMDB poster if empty
        if (! empty($info['cover_big']) && empty($vod->logo)) {
            $updateData['logo'] = $info['cover_big'];
        }
        if (! empty($info['cover_big']) && empty($vod->logo_internal)) {
            $updateData['logo_internal'] = $info['cover_big'];
        }

        // Set display title to TMDB title (manual match = user intent to correct the title)
        $tmdbTitle = $details['title'] ?? $metadata['title'] ?? null;
        if ($tmdbTitle) {
            $updateData['title_custom'] = $tmdbTitle;
        }

        $updateData['last_metadata_fetch'] = now();

        $vod->update($updateData);

        $vodName = $vod->title_custom ?: $vod->title ?: $vod->name;

        Log::info('Manual TMDB search: Applied full metadata to VOD', [
            'vod_id' => $vod->id,
            'vod_name' => $vodName,
            'tmdb_id' => $metadata['tmdb_id'],
            'imdb_id' => $metadata['imdb_id'] ?? null,
            'has_details' => $details !== null,
        ]);

        Notification::make()
            ->success()
            ->title(__('TMDB Metadata Applied'))
            ->body("Successfully linked \"{$vodName}\" to \"{$tmdbTitle}\" (TMDB: {$metadata['tmdb_id']}) with full metadata.")
            ->send();

        $this->unmountAction();
    }

    /**
     * Fetch full metadata from TMDB and apply it to a series.
     */
    protected function applySeriesMetadata(TmdbService $tmdbService, Series $series, int $tmdbId): void
    {
        $metadata = $tmdbService->applyTvSeriesSelection($tmdbId);
        if (! $metadata) {
            Notification::make()
                ->danger()
                ->title(__('Error'))
                ->body(__('Failed to fetch TMDB data for this series.'))
                ->send();

            return;
        }

        $updateData = [
            'tmdb_id' => $metadata['tmdb_id'],
            'last_metadata_fetch' => now(),
        ];

        if (! empty($metadata['tvdb_id'])) {
            $updateData['tvdb_id'] = $metadata['tvdb_id'];
        }
        if (! empty($metadata['imdb_id'])) {
            $updateData['imdb_id'] = $metadata['imdb_id'];
        }

        $seriesMetadata = $series->metadata ?? [];
        $seriesMetadata['tmdb_id'] = $metadata['tmdb_id'];
        if (! empty($metadata['tvdb_id'])) {
            $seriesMetadata['tvdb_id'] = $metadata['tvdb_id'];
        }
        if (! empty($metadata['imdb_id'])) {
            $seriesMetadata['imdb_id'] = $metadata['imdb_id'];
        }

        // Fetch full series details to populate metadata
        $details = $tmdbService->getTvSeriesDetails($tmdbId);
        if ($details) {
            if (! empty($details['tvdb_id']) && empty($updateData['tvdb_id'])) {
                $updateData['tvdb_id'] = $details['tvdb_id'];
                $seriesMetadata['tvdb_id'] = $details['tvdb_id'];
            }
            if (! empty($details['imdb_id']) && empty($updateData['imdb_id'])) {
                $updateData['imdb_id'] = $details['imdb_id'];
                $seriesMetadata['imdb_id'] = $details['imdb_id'];
            }

            if (! empty($details['poster_url'])) {
                $updateData['cover'] = $details['poster_url'];
            }

            if (! empty($details['overview'])) {
                $updateData['plot'] = $details['overview'];
            }

            if (! empty($details['genres']) && (empty($series->genre) || ($series->genre ?? '') === 'Uncategorized')) {
                $updateData['genre'] = $details['genres'];

                $primaryGenre = is_string($details['genres'])
                    ? explode(', ', $details['genres'])[0]
                    : (is_array($details['genres']) ? $details['genres'][0] : null);

                if ($primaryGenre) {
                    $currentCategory = $series->category_id ? Category::find($series->category_id) : null;
                    if (! $currentCategory || $currentCategory->name === 'Uncategorized') {
                        $category = Category::firstOrCreate(
                            [
                                'playlist_id' => $series->playlist_id,
                                'name' => $primaryGenre,
                            ],
                            [
                                'name_internal' => $primaryGenre,
                                'user_id' => $series->user_id,
                            ]
                        );
                        $updateData['category_id'] = $category->id;
                        $updateData['source_category_id'] = $category->id;
                    }
                }
            }

            if (! empty($details['first_air_date']) && empty($series->release_date)) {
                $updateData['release_date'] = $details['first_air_date'];
            }

            if (! empty($details['vote_average']) && empty($series->rating)) {
                $updateData['rating'] = $details['vote_average'];
            }

            if (! empty($details['backdrop_url'])) {
                $updateData['backdrop_path'] = json_encode([$details['backdrop_url']]);
            }

            if (! empty($details['cast'])) {
                $updateData['cast'] = is_array($details['cast']) ? implode(', ', $details['cast']) : $details['cast'];
            }

            if (! empty($details['director'])) {
                $updateData['director'] = is_array($details['director']) ? implode(', ', $details['director']) : $details['director'];
            }

            if (! empty($details['youtube_trailer'])) {
                $updateData['youtube_trailer'] = $details['youtube_trailer'];
            }
        }

        $updateData['metadata'] = $seriesMetadata;

        // Set series name to TMDB name (manual match = user intent to correct the name)
        $tmdbName = $details['name'] ?? $metadata['name'] ?? null;
        if ($tmdbName) {
            $updateData['name'] = $tmdbName;
        }

        $series->update($updateData);

        Log::info('Manual TMDB search: Applied full metadata to series', [
            'series_id' => $series->id,
            'series_name' => $series->name,
            'tmdb_id' => $metadata['tmdb_id'],
            'tvdb_id' => $metadata['tvdb_id'] ?? null,
            'imdb_id' => $metadata['imdb_id'] ?? null,
            'has_details' => $details !== null,
        ]);

        Notification::make()
            ->success()
            ->title(__('TMDB Metadata Applied'))
            ->body("Successfully linked \"{$series->name}\" to TMDB: {$metadata['tmdb_id']} with full metadata.")
            ->send();

        $this->unmountAction();
    }
}
