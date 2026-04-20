<?php

namespace App\Models;

use App\Enums\PlaylistSourceType;
use App\Jobs\FetchTmdbIds;
use App\Jobs\SyncSeriesStrmFiles;
use App\Services\XtreamService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Tags\HasTags;

class Series extends Model
{
    use HasFactory;
    use HasTags;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'new' => 'boolean',
        'source_category_id' => 'integer',
        'source_series_id' => 'integer',
        'user_id' => 'integer',
        'playlist_id' => 'integer',
        'category_id' => 'integer',
        'tmdb_id' => 'integer',
        'tvdb_id' => 'integer',
        'imdb_id' => 'string',
        // 'release_date' => 'date', // Not always well formed date, don't attempt to cast
        'rating_5based' => 'integer',
        'enabled' => 'boolean',
        'backdrop_path' => 'array',
        'metadata' => 'array',
        'sync_settings' => 'array',
        'last_metadata_fetch' => 'datetime',
        'last_modified' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function streamFileSetting(): BelongsTo
    {
        return $this->belongsTo(StreamFileSetting::class);
    }

    public function customPlaylists(): BelongsToMany
    {
        return $this->belongsToMany(CustomPlaylist::class, 'series_custom_playlist');
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }

    public function enabled_episodes(): HasMany
    {
        return $this->hasMany(Episode::class)->where('enabled', true);
    }

    public function getMovieDbIds(): array
    {
        $tvdbId = $this->tvdb_id ?? $this->metadata['tvdb_id'] ?? $this->metadata['tvdb'] ?? null;
        $tmdbId = $this->tmdb_id ?? $this->metadata['tmdb_id'] ?? $this->metadata['tmdb'] ?? null;
        $imdbId = $this->imdb_id ?? $this->metadata['imdb_id'] ?? $this->metadata['imdb'] ?? null;

        return [
            'tmdb' => $tmdbId !== null ? (int) $tmdbId : null,
            'tvdb' => $tvdbId,
            'imdb' => $imdbId,
        ];
    }

    public function scopeHasSeriesId(Builder $query): Builder
    {
        $isPgsql = config('database.connections.'.config('database.default').'.driver') === 'pgsql';

        return $query->where(function (Builder $q) use ($isPgsql) {
            $q->whereNotNull('tmdb_id')
                ->orWhereNotNull('tvdb_id')
                ->orWhereNotNull('imdb_id');

            if ($isPgsql) {
                $q->orWhereRaw("metadata::jsonb ?? 'tmdb_id'")
                    ->orWhereRaw("metadata::jsonb ?? 'tmdb'")
                    ->orWhereRaw("metadata::jsonb ?? 'tvdb_id'")
                    ->orWhereRaw("metadata::jsonb ?? 'tvdb'")
                    ->orWhereRaw("metadata::jsonb ?? 'imdb_id'")
                    ->orWhereRaw("metadata::jsonb ?? 'imdb'");
            }
        });
    }

    public function scopeMissingSeriesId(Builder $query): Builder
    {
        $query->whereNull('tmdb_id')
            ->whereNull('tvdb_id')
            ->whereNull('imdb_id');

        if (config('database.connections.'.config('database.default').'.driver') !== 'pgsql') {
            return $query;
        }

        return $query->where(function (Builder $q) {
            $q->whereNull('metadata')
                ->orWhere(function (Builder $inner) {
                    $inner->whereRaw("NOT (metadata::jsonb ?? 'tmdb_id')")
                        ->whereRaw("NOT (metadata::jsonb ?? 'tmdb')")
                        ->whereRaw("NOT (metadata::jsonb ?? 'tvdb_id')")
                        ->whereRaw("NOT (metadata::jsonb ?? 'tvdb')")
                        ->whereRaw("NOT (metadata::jsonb ?? 'imdb_id')")
                        ->whereRaw("NOT (metadata::jsonb ?? 'imdb')");
                });
        });
    }

    /**
     * Check if the series has TMDB/TVDB/IMDB metadata.
     */
    public function getHasMetadataAttribute(): bool
    {
        // Check if the series has TMDB, TVDB, or IMDB IDs
        // Also check metadata array for backward compatibility
        $ids = $this->getMovieDbIds();

        return ! empty($ids['tmdb']) || ! empty($ids['tvdb']) || ! empty($ids['imdb']);
    }

    public function fetchMetadata($refresh = false, $sync = true, bool $dispatchTmdb = true)
    {
        // Skip the provider call if data is still fresh (unless a forced refresh is requested).
        $isFresh = ! $refresh && $this->last_metadata_fetch && $this->last_modified
            && $this->last_metadata_fetch >= $this->last_modified;

        try {
            if (! $isFresh) {
                $playlist = $this->playlist;

                // Get settings instance
                $settings = app(GeneralSettings::class);

                // For Xtream playlists, use XtreamService
                if (! $playlist->xtream && $playlist->source_type !== PlaylistSourceType::Xtream) {
                    // Not an Xtream playlist and not Emby, no metadata source available
                    return false;
                }

                $xtream = XtreamService::make($playlist);

                if (! $xtream) {
                    Notification::make()
                        ->danger()
                        ->title('Series metadata sync failed')
                        ->body('Unable to connect to Xtream API provider to get series info, unable to fetch metadata.')
                        ->broadcast($playlist->user)
                        ->sendToDatabase($playlist->user);

                    return false;
                }

                $detail = $xtream->getSeriesInfo($this->source_series_id);
                $seasons = $detail['seasons'] ?? [];
                $info = $detail['info'] ?? [];
                $eps = $detail['episodes'] ?? [];
                $batchNo = Str::orderedUuid()->toString();

                // Use the provider-supplied timestamp when available; fall back to now + 24 hours
                // so the freshness check treats this fetch as valid for one day.
                $providerLastModified = isset($info['last_modified']) && $info['last_modified']
                    ? Carbon::createFromTimestamp((int) $info['last_modified'])
                    : now()->addDay();

                $update = [
                    'last_metadata_fetch' => now(),
                    'last_modified' => $providerLastModified,
                    'metadata' => $info, // Store raw metadata
                ];
                if ($refresh) {
                    $item = $detail['info'] ?? null;
                    if ($item) {
                        $backdropPath = $item['backdrop_path'] ?? [];
                        $update = array_merge($update, [
                            'name' => $item['name'],
                            'cover' => $item['cover'] ?? null,
                            'plot' => $item['plot'] ?? null,
                            'genre' => $item['genre'] ?? null,
                            'release_date' => $item['releaseDate'] ?? $item['release_date'] ?? null,
                            'cast' => $item['cast'] ?? null,
                            'director' => $item['director'] ?? null,
                            'rating' => $item['rating'] ?? null,
                            'rating_5based' => (float) ($item['rating_5based'] ?? 0),
                            'backdrop_path' => is_string($backdropPath) ? json_decode($backdropPath, true) : $backdropPath,
                            'youtube_trailer' => $item['youtube_trailer'] ?? null,
                        ]);
                    }
                }

                // If episodes found, process them
                if (count($eps) > 0) {
                    // Process the series episodes
                    $playlistCategory = $this->category;
                    foreach ($eps as $season => $episodes) {
                        // Check if the season exists in the playlist
                        $playlistSeason = $this->seasons()
                            ->where('season_number', $season)
                            ->first();

                        // Get season info if available
                        $seasonInfo = $seasons[$season] ?? [];

                        if (! $playlistSeason) {
                            // Create the season if it doesn't exist
                            $playlistSeason = $this->seasons()->create([
                                'season_number' => $season,
                                'name' => $seasonInfo['name'] ?? 'Season '.str_pad($season, 2, '0', STR_PAD_LEFT),
                                'source_season_id' => $seasonInfo['id'] ?? null,
                                'episode_count' => (int) ($seasonInfo['episode_count'] ?? 0),
                                'cover' => $seasonInfo['cover'] ?? null,
                                'cover_big' => $seasonInfo['cover_big'] ?? null,
                                'user_id' => $playlist->user_id,
                                'playlist_id' => $playlist->id,
                                'series_id' => $this->id,
                                'category_id' => $playlistCategory->id,
                                'import_batch_no' => $batchNo,
                                'metadata' => $seasonInfo,
                            ]);
                        } else {
                            // Update the season if it exists
                            $playlistSeason->update([
                                'new' => false,
                                'source_season_id' => $seasonInfo['id'] ?? null,
                                'category_id' => $playlistCategory->id,
                                'episode_count' => (int) ($seasonInfo['episode_count'] ?? 0),
                                'cover' => $seasonInfo['cover'] ?? null,
                                'cover_big' => $seasonInfo['cover_big'] ?? null,
                                'series_id' => $this->id,
                                'import_batch_no' => $batchNo,
                                'metadata' => $seasonInfo,
                            ]);
                        }

                        // Process each episode in the season
                        $bulk = [];
                        $seasonTmdbId = $seasonInfo['tmdb'] ?? $seasonInfo['tmdb_id'] ?? null;
                        foreach ($episodes as $ep) {
                            $url = $xtream->buildSeriesUrl($ep['id'], $ep['container_extension']);
                            $title = preg_match('/S\d{2}E\d{2} - (.*)/', $ep['title'], $m) ? $m[1] : null;
                            if (! $title) {
                                $title = $ep['title'] ?? "Episode {$ep['episode_num']}";
                            }
                            $bulk[] = [
                                'title' => $title,
                                'source_episode_id' => (int) $ep['id'],
                                'import_batch_no' => $batchNo,
                                'user_id' => $playlist->user_id,
                                'playlist_id' => $playlist->id,
                                'series_id' => $this->id,
                                'season_id' => $playlistSeason->id,
                                'episode_num' => (int) $ep['episode_num'],
                                'container_extension' => $ep['container_extension'],
                                'custom_sid' => $ep['custom_sid'] ?? null,
                                'added' => $ep['added'] ?? null,
                                'season' => (int) $season,
                                'url' => $url,
                                'info' => json_encode([
                                    'release_date' => $ep['info']['release_date'] ?? null,
                                    'plot' => $ep['info']['plot'] ?? $seasonInfo['plot'] ?? null,
                                    'duration_secs' => $ep['info']['duration_secs'] ?? null,
                                    'duration' => $ep['info']['duration'] ?? null,
                                    'movie_image' => $ep['info']['movie_image'] ?? null,
                                    'bitrate' => $ep['info']['bitrate'] ?? 0,
                                    'rating' => $ep['info']['rating'] ?? null,
                                    'season' => (int) $season,
                                    'tmdb_id' => $ep['info']['tmdb_id'] ?? $seasonTmdbId ?? null,
                                    'cover_big' => $ep['info']['cover_big'] ?? null,
                                ]),
                            ];
                        }

                        // Upsert the episodes in bulk
                        Episode::upsert(
                            $bulk,
                            uniqueBy: ['source_episode_id', 'playlist_id', 'series_id'],
                            update: [
                                'title',
                                'import_batch_no',
                                'episode_num',
                                'container_extension',
                                'custom_sid',
                                'added',
                                'season',
                                'url',
                                'info',
                            ]
                        );
                    }
                }

                // Update last fetched timestamp for the series (always, regardless of episode count).
                $this->update($update);

                $jobs = [];
                if ($dispatchTmdb && $settings->tmdb_auto_lookup_on_import && $this->enabled) {
                    // If TMDB auto lookup enabled, dispatch job to fetch TMDB metadata for episodes
                    $jobs[] = new FetchTmdbIds(
                        seriesIds: [$this->id],
                        overwriteExisting: $refresh,
                        sendCompletionNotification: false,
                    );
                }
                if ($sync && $this->enabled) {
                    // Dispatch the job to sync .strm files
                    $jobs[] = new SyncSeriesStrmFiles(series: $this, notify: false);
                }

                if (count($jobs) > 0) {
                    Bus::chain($jobs)->dispatch();
                }

            }

            // Data is fresh, return true
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to fetch metadata for series '.$this->id, ['exception' => $e]);
        }

        return false;
    }

    /**
     * Get the custom group name for a specific custom playlist
     */
    public function getCustomCategoryName(string $customPlaylistUuid): string
    {
        $tag = $this->tags()
            ->where('type', $customPlaylistUuid.'-category')
            ->first();

        return $tag ? $tag->getAttributeValue('name') : 'Uncategorized';
    }
}
