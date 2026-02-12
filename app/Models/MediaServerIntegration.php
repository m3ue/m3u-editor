<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class MediaServerIntegration extends Model
{
    use HasFactory;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * The attributes that should have default values.
     *
     * @var array
     */
    protected $attributes = [
        'port' => 8096,
        'enabled' => true,
        'ssl' => false,
        'genre_handling' => 'primary',
        'import_movies' => true,
        'import_series' => true,
        'auto_sync' => true,
        'status' => 'idle',
        'progress' => 0,
        'movie_progress' => 0,
        'series_progress' => 0,
        'total_movies' => 0,
        'total_series' => 0,
        'metadata_source' => 'tmdb',
        'auto_fetch_metadata' => true,
        'scan_recursive' => true,
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'port' => 'integer',
        'enabled' => 'boolean',
        'ssl' => 'boolean',
        'import_movies' => 'boolean',
        'import_series' => 'boolean',
        'auto_sync' => 'boolean',
        'last_synced_at' => 'datetime',
        'sync_stats' => 'array',
        'user_id' => 'integer',
        'playlist_id' => 'integer',
        'progress' => 'integer',
        'movie_progress' => 'integer',
        'series_progress' => 'integer',
        'total_movies' => 'integer',
        'total_series' => 'integer',
        'available_libraries' => 'array',
        'selected_library_ids' => 'array',
        'local_media_paths' => 'array',
        'video_extensions' => 'array',
        'scan_recursive' => 'boolean',
        'auto_fetch_metadata' => 'boolean',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array
     */
    protected $hidden = [
        'api_key',
    ];

    /**
     * Get the user that owns this integration.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the playlist associated with this integration.
     * Content synced from the media server is stored in this playlist.
     */
    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    /**
     * Get the channels (VOD movies) through the associated playlist.
     */
    public function channels(): HasManyThrough
    {
        return $this->hasManyThrough(
            Channel::class,
            Playlist::class,
            'id',
            'playlist_id',
            'playlist_id',
            'id'
        );
    }

    /**
     * Get the series through the associated playlist.
     */
    public function series(): HasManyThrough
    {
        return $this->hasManyThrough(
            Series::class,
            Playlist::class,
            'id',
            'playlist_id',
            'playlist_id',
            'id'
        );
    }

    /**
     * Get the base URL for the media server.
     */
    public function getBaseUrlAttribute(): string
    {
        $protocol = $this->ssl ? 'https' : 'http';

        return "{$protocol}://{$this->host}:{$this->port}";
    }

    /**
     * Check if this is an Emby server.
     */
    public function isEmby(): bool
    {
        return $this->type === 'emby';
    }

    /**
     * Check if this is a Jellyfin server.
     */
    public function isJellyfin(): bool
    {
        return $this->type === 'jellyfin';
    }

    /**
     * Check if this is a Plex server.
     */
    public function isPlex(): bool
    {
        return $this->type === 'plex';
    }

    /**
     * Check if this is a local media integration.
     */
    public function isLocal(): bool
    {
        return $this->type === 'local';
    }

    /**
     * Check if this integration requires network connectivity.
     * Local media does not require network connectivity.
     */
    public function requiresNetwork(): bool
    {
        return ! $this->isLocal();
    }

    /**
     * Get the configured local media paths for a specific type.
     *
     * @param  string|null  $type  'movies' or 'tvshows', null for all
     * @return array<array{path: string, type: string, name: string}>
     */
    public function getLocalMediaPathsForType(?string $type = null): array
    {
        $paths = $this->local_media_paths ?? [];

        if ($type === null) {
            return $paths;
        }

        return array_filter($paths, fn ($path) => ($path['type'] ?? '') === $type);
    }

    /**
     * Get the default video extensions to scan for.
     *
     * @return array<string>
     */
    public function getVideoExtensions(): array
    {
        return $this->video_extensions ?? [
            'mp4', 'mkv', 'avi', 'mov', 'wmv', 'flv', 'webm',
            'm4v', 'mpeg', 'mpg', 'ts', 'm2ts', 'mts', 'vob',
        ];
    }

    /**
     * Get networks associated with this integration.
     */
    public function networks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Network::class);
    }

    /**
     * Scope to only enabled integrations.
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Get selected library IDs for a specific type (movies or tvshows).
     *
     * @param  string  $type  'movies' or 'tvshows'
     * @return array<string>
     */
    public function getSelectedLibraryIdsForType(string $type): array
    {
        $selectedIds = $this->selected_library_ids ?? [];
        $availableLibraries = $this->available_libraries ?? [];

        if (empty($selectedIds) || empty($availableLibraries)) {
            return [];
        }

        return collect($availableLibraries)
            ->filter(fn ($lib) => $lib['type'] === $type && in_array($lib['id'], $selectedIds))
            ->pluck('id')
            ->toArray();
    }

    /**
     * Check if any libraries of a specific type are selected.
     *
     * @param  string  $type  'movies' or 'tvshows'
     */
    public function hasSelectedLibrariesOfType(string $type): bool
    {
        return ! empty($this->getSelectedLibraryIdsForType($type));
    }

    /**
     * Get the names of selected libraries for display.
     *
     * @return array<string>
     */
    public function getSelectedLibraryNames(): array
    {
        $selectedIds = $this->selected_library_ids ?? [];
        $availableLibraries = $this->available_libraries ?? [];

        if (empty($selectedIds) || empty($availableLibraries)) {
            return [];
        }

        return collect($availableLibraries)
            ->filter(fn ($lib) => in_array($lib['id'], $selectedIds))
            ->pluck('name')
            ->toArray();
    }

    /**
     * Validate that selected libraries still exist on the media server.
     * Returns missing library IDs.
     *
     * @param  array  $currentLibraries  Libraries fetched from the server
     * @return array<string> IDs of libraries that were selected but no longer exist
     */
    public function validateSelectedLibraries(array $currentLibraries): array
    {
        $selectedIds = $this->selected_library_ids ?? [];
        $currentIds = collect($currentLibraries)->pluck('id')->toArray();

        return array_diff($selectedIds, $currentIds);
    }
}
