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
        'sports_repeat_league_in_filename' => 'boolean',
        'sports_include_event_title' => 'boolean',
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
        return $query->whereIn('type', ['series', 'sports']);
    }

    public function scopeForVod(Builder $query): Builder
    {
        return $query->whereIn('type', ['vod', 'sports']);
    }

    public function scopeForSports(Builder $query): Builder
    {
        return $query->where('type', 'sports');
    }

    /**
     * Convert the model to an array format compatible with sync jobs.
     */
    public function toSyncSettings(): array
    {
        return [
            'type' => $this->type,
            'enabled' => $this->enabled,
            'url_type' => $this->url_type ?? 'proxy',
            'sync_location' => $this->location,
            'path_structure' => $this->path_structure ?? [],
            'filename_metadata' => $this->filename_metadata ?? [],
            'folder_metadata' => $this->folder_metadata ?? [],
            'tmdb_id_format' => $this->tmdb_id_format,
            'tmdb_id_apply_to' => $this->tmdb_id_apply_to ?? 'episodes',
            'sports_league_source' => $this->sports_league_source ?? 'group',
            'sports_static_league' => $this->sports_static_league,
            'sports_season_source' => $this->sports_season_source ?? 'title_year',
            'sports_episode_strategy' => $this->sports_episode_strategy ?? 'sequential_per_season',
            'sports_repeat_league_in_filename' => $this->sports_repeat_league_in_filename ?? true,
            'sports_include_event_title' => $this->sports_include_event_title ?? true,
            'clean_special_chars' => $this->clean_special_chars,
            'remove_consecutive_chars' => $this->remove_consecutive_chars,
            'replace_char' => $this->replace_char,
            'name_filter_enabled' => $this->name_filter_enabled,
            'name_filter_patterns' => $this->name_filter_patterns ?? [],
            'generate_nfo' => $this->generate_nfo,
            'refresh_media_server' => $this->refresh_media_server,
            'media_server_integration_id' => $this->media_server_integration_id,
            'refresh_delay_seconds' => $this->refresh_delay_seconds ?? 5,
        ];
    }
}
