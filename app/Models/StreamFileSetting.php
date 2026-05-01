<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StreamFileSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'type',
        'enabled',
        'location',
        'path_structure',
        'filename_metadata',
        'folder_metadata',
        'tmdb_id_format',
        'tmdb_id_apply_to',
        'clean_special_chars',
        'remove_consecutive_chars',
        'replace_char',
        'name_filter_enabled',
        'name_filter_patterns',
        'generate_nfo',
        'refresh_media_server',
        'media_server_integration_id',
        'refresh_delay_seconds',
        'url_type',
        'movie_format',
        'episode_format',
        'trash_guide_naming_enabled',
        'version_detection_pattern',
        'group_versions',
        'use_stream_stats',
        'trash_movie_components',
        'trash_episode_components',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'path_structure' => 'array',
        'filename_metadata' => 'array',
        'folder_metadata' => 'array',
        'clean_special_chars' => 'boolean',
        'remove_consecutive_chars' => 'boolean',
        'name_filter_enabled' => 'boolean',
        'name_filter_patterns' => 'array',
        'generate_nfo' => 'boolean',
        'refresh_media_server' => 'boolean',
        'refresh_delay_seconds' => 'integer',
        'trash_guide_naming_enabled' => 'boolean',
        'group_versions' => 'boolean',
        'use_stream_stats' => 'boolean',
        'trash_movie_components' => 'array',
        'trash_episode_components' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function series(): HasMany
    {
        return $this->hasMany(Series::class, 'stream_file_setting_id');
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class, 'stream_file_setting_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class, 'stream_file_setting_id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'stream_file_setting_id');
    }

    public function mediaServerIntegration(): BelongsTo
    {
        return $this->belongsTo(MediaServerIntegration::class);
    }

    public function scopeForSeries(Builder $query): Builder
    {
        return $query->where('type', 'series');
    }

    public function scopeForVod(Builder $query): Builder
    {
        return $query->where('type', 'vod');
    }

    /**
     * Convert the model to an array format compatible with sync jobs.
     */
    public function toSyncSettings(): array
    {
        return [
            'enabled' => $this->enabled,
            'url_type' => $this->url_type ?? 'proxy',
            'sync_location' => $this->location,
            'path_structure' => $this->path_structure ?? [],
            'filename_metadata' => $this->filename_metadata ?? [],
            'folder_metadata' => $this->folder_metadata ?? [],
            'tmdb_id_format' => $this->tmdb_id_format,
            'tmdb_id_apply_to' => $this->tmdb_id_apply_to ?? 'episodes',
            'clean_special_chars' => $this->clean_special_chars,
            'remove_consecutive_chars' => $this->remove_consecutive_chars,
            'replace_char' => $this->replace_char,
            'name_filter_enabled' => $this->name_filter_enabled,
            'name_filter_patterns' => $this->name_filter_patterns ?? [],
            'generate_nfo' => $this->generate_nfo,
            'refresh_media_server' => $this->refresh_media_server,
            'media_server_integration_id' => $this->media_server_integration_id,
            'refresh_delay_seconds' => $this->refresh_delay_seconds ?? 5,
            'trash_guide_naming_enabled' => $this->trash_guide_naming_enabled ?? false,
            'movie_format' => $this->movie_format,
            'episode_format' => $this->episode_format,
            'version_detection_pattern' => $this->version_detection_pattern,
            'group_versions' => $this->group_versions ?? true,
            'use_stream_stats' => $this->use_stream_stats ?? true,
            'trash_movie_components' => $this->trash_movie_components ?? [],
            'trash_episode_components' => $this->trash_episode_components ?? [],
        ];
    }
}
