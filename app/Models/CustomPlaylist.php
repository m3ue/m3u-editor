<?php

namespace App\Models;

use App\Enums\PlaylistChannelId;
use App\Traits\ShortUrlTrait;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Spatie\Tags\HasTags;
use Spatie\Tags\Tag;

class CustomPlaylist extends Model
{
    use HasFactory;
    use HasTags;
    use ShortUrlTrait;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'dummy_epg' => 'boolean',
        'dummy_epg_fallback_order' => 'array',
        'output_tvg_type' => 'boolean',
        'short_urls' => 'array',
        'proxy_options' => 'array',
        'short_urls_enabled' => 'boolean',
        'include_series_in_m3u' => 'boolean',
        'include_networks_in_m3u' => 'boolean',
        'include_vod_in_m3u' => 'boolean',
        'custom_headers' => 'array',
        'strict_live_ts' => 'boolean',
        'use_sticky_session' => 'boolean',
        'id_channel_by' => PlaylistChannelId::class,
        'disable_m3u_xtream_format' => 'boolean',
        'processing_config' => 'array',
        'aiostreams_integration_id' => 'integer',
    ];

    public function enabledProcessingRules(): SupportCollection
    {
        return collect($this->processing_config ?? [])
            ->filter(fn (array $rule): bool => $rule['enabled'] ?? false)
            ->values();
    }

    public function hasEnabledProcessingRules(): bool
    {
        return $this->enabledProcessingRules()->isNotEmpty();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function streamProfile(): BelongsTo
    {
        return $this->belongsTo(StreamProfile::class);
    }

    /**
     * The AIOStreams integration that this custom playlist is permitted to access.
     */
    public function aiostreamsIntegration(): BelongsTo
    {
        return $this->belongsTo(MediaServerIntegration::class, 'aiostreams_integration_id');
    }

    public function dvrSetting(): HasOne
    {
        return $this->hasOne(DvrSetting::class);
    }

    public function requestSetting(): HasOne
    {
        return $this->hasOne(PlaylistRequestSetting::class);
    }

    public function vodStreamProfile(): BelongsTo
    {
        return $this->belongsTo(StreamProfile::class, 'vod_stream_profile_id');
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'channel_custom_playlist')
            ->withPivot(['channel_number', 'sort']);
    }

    public function enabled_channels(): BelongsToMany
    {
        return $this->channels()
            ->where('enabled', true);
    }

    public function series(): BelongsToMany
    {
        return $this->belongsToMany(Series::class, 'series_custom_playlist')
            ->withPivot(['sort']);
    }

    public function enabled_series(): BelongsToMany
    {
        return $this->series()
            ->where('enabled', true);
    }

    public function live_channels(): BelongsToMany
    {
        return $this->channels()
            ->where('is_vod', false);
    }

    public function enabled_live_channels(): BelongsToMany
    {
        return $this->live_channels()
            ->where('enabled', true);
    }

    public function vod_channels(): BelongsToMany
    {
        return $this->channels()
            ->where('is_vod', true);
    }

    public function enabled_vod_channels(): BelongsToMany
    {
        return $this->vod_channels()
            ->where('enabled', true);
    }

    public function customChannels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function groups(): MorphToMany
    {
        return $this->groupTags();
    }

    public function groupTags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')
            ->where('type', $this->uuid);
    }

    public function categories(): MorphToMany
    {
        return $this->categoryTags();
    }

    public function categoryTags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')
            ->where('type', $this->uuid.'-category');
    }

    // public function playlists(): HasManyThrough
    // {
    //     return $this->hasManyThrough(
    //         Playlist::class,
    //         CustomPlaylistPivot::class,
    //         'custom_playlist_id',
    //         'channel_id',
    //         'id',
    //         'channel_id'
    //     );
    // }

    /**
     * The live or VOD groups a channel filter can select for this playlist, as a query of
     * {@see CustomPlaylistGroup} records keyed by name.
     *
     * Mirrors the categories delivered to clients: the custom group tags assigned to this
     * playlist's channels, plus the provider group names of any channels that carry no
     * custom tag and therefore fall back to their original group.
     */
    public function filterableGroupsQuery(bool $isVod): EloquentBuilder
    {
        $tagNames = DB::table('tags')
            ->join('taggables', 'tags.id', '=', 'taggables.tag_id')
            ->where('tags.type', $this->uuid)
            ->where('taggables.taggable_type', Channel::class)
            ->whereIn('taggables.taggable_id', $this->filterableChannelIdsQuery($isVod))
            ->select('tags.name->en as name');

        $fallbackNames = DB::table('channels')
            ->whereIn('channels.id', $this->filterableChannelIdsQuery($isVod))
            ->whereNotNull('channels.group')
            ->whereNotExists(fn (QueryBuilder $query) => $this->whereTaggedWith($query, 'channels.id', Channel::class, $this->uuid))
            ->select('channels.group as name');

        return CustomPlaylistGroup::fromNameUnion($tagNames->union($fallbackNames));
    }

    /**
     * The series categories a channel filter can select for this playlist, as a query of
     * {@see CustomPlaylistGroup} records keyed by name.
     */
    public function filterableCategoriesQuery(): EloquentBuilder
    {
        $categoryTagType = $this->uuid.'-category';

        $tagNames = DB::table('tags')
            ->join('taggables', 'tags.id', '=', 'taggables.tag_id')
            ->where('tags.type', $categoryTagType)
            ->where('taggables.taggable_type', Series::class)
            ->whereIn('taggables.taggable_id', $this->filterableSeriesIdsQuery())
            ->select('tags.name->en as name');

        $fallbackNames = DB::table('categories')
            ->join('series', 'series.category_id', '=', 'categories.id')
            ->whereIn('series.id', $this->filterableSeriesIdsQuery())
            ->whereNotExists(fn (QueryBuilder $query) => $this->whereTaggedWith($query, 'series.id', Series::class, $categoryTagType))
            ->select('categories.name as name');

        return CustomPlaylistGroup::fromNameUnion($tagNames->union($fallbackNames));
    }

    /**
     * A fresh subquery of this playlist's enabled live or VOD channel ids.
     *
     * Returned fresh on each call because the query builder mutates the relation instance,
     * and the ids are needed on both sides of the group union.
     */
    private function filterableChannelIdsQuery(bool $isVod): BelongsToMany
    {
        return $this->channels()
            ->where('channels.enabled', true)
            ->where('channels.is_vod', $isVod)
            ->select('channels.id');
    }

    /**
     * A fresh subquery of this playlist's enabled series ids.
     */
    private function filterableSeriesIdsQuery(): BelongsToMany
    {
        return $this->series()
            ->where('series.enabled', true)
            ->select('series.id');
    }

    /**
     * Constrain an EXISTS subquery to rows carrying a tag of the given type.
     */
    private function whereTaggedWith(QueryBuilder $query, string $idColumn, string $taggableType, string $tagType): QueryBuilder
    {
        return $query->selectRaw('1')
            ->from('taggables')
            ->join('tags', 'tags.id', '=', 'taggables.tag_id')
            ->whereColumn('taggables.taggable_id', $idColumn)
            ->where('taggables.taggable_type', $taggableType)
            ->where('tags.type', $tagType);
    }

    public function playlistAuths(): MorphToMany
    {
        return $this->morphToMany(PlaylistAuth::class, 'authenticatable');
    }

    public function postProcesses(): MorphToMany
    {
        return $this->morphToMany(PostProcess::class, 'processable');
    }

    public function getAutoSortAttribute(): bool
    {
        return true;
    }

    /**
     * Get all unique source playlists that have channels assigned to this custom playlist.
     * This is useful for determining which provider credentials need to be configured
     * when creating a Playlist Alias.
     */
    public function getSourcePlaylists(): Collection
    {
        $playlistIds = $this->channels()
            ->whereNotNull('playlist_id')
            ->distinct()
            ->pluck('playlist_id');

        return Playlist::query()->whereKey($playlistIds)->get();
    }

    /**
     * Get source playlists with their xtream config info for alias configuration.
     * Returns an array of playlists with their URL base for matching.
     *
     * @return array<int, array{id: int, name: string, url: string|null}>
     */
    public function getSourcePlaylistsForAlias(): array
    {
        return $this->getSourcePlaylists()
            ->map(function (Playlist $playlist) {
                $url = $playlist->xtream_config['url'] ?? null;

                return [
                    'id' => $playlist->id,
                    'name' => $playlist->name,
                    'url' => $url ? rtrim($url, '/') : null,
                ];
            })
            ->filter(fn ($config) => $config['url'] !== null)
            ->toArray();
    }

    /**
     * Check if any source playlists have provider profiles enabled.
     * When this returns true, proxy mode should be required for proper connection pooling.
     */
    public function hasPooledSourcePlaylists(): bool
    {
        return $this->channels()
            ->whereNotNull('playlist_id')
            ->whereHas('playlist', fn ($query) => $query->where('profiles_enabled', true))
            ->exists();
    }

    /**
     * Get source playlists that have provider profiles enabled.
     */
    public function getPooledSourcePlaylists(): Collection
    {
        $playlistIds = $this->channels()
            ->whereNotNull('playlist_id')
            ->distinct()
            ->pluck('playlist_id');

        return Playlist::query()->whereKey($playlistIds)
            ->where('profiles_enabled', true)
            ->get();
    }

    public function playlistViewers(): MorphMany
    {
        return $this->morphMany(PlaylistViewer::class, 'viewerable');
    }

    public function enableProxy(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value && ! $this->user?->canUseProxy()) {
                    return false;
                }

                return $value;
            }
        );
    }
}
