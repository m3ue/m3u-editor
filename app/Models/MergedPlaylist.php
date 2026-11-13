<?php

namespace App\Models;

use App\Enums\PlaylistChannelId;
use App\Pivots\MergedPlaylistPivot;
use App\Traits\HasDefaultAuth;
use App\Traits\ShortUrlTrait;
use Closure;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

class MergedPlaylist extends Model
{
    use HasDefaultAuth;
    use HasFactory;
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
        'dummy_epg_days' => 'integer',
        'dummy_epg_fallback_order' => 'array',
        'output_tvg_type' => 'boolean',
        'short_urls' => 'array',
        'proxy_options' => 'array',
        'short_urls_enabled' => 'boolean',
        'include_series_in_m3u' => 'boolean',
        'include_networks_in_m3u' => 'boolean',
        'include_vod_in_m3u' => 'boolean',
        'sort_by_channel_number' => 'boolean',
        'custom_headers' => 'array',
        'strict_live_ts' => 'boolean',
        'use_sticky_session' => 'boolean',
        'id_channel_by' => PlaylistChannelId::class,
        'disable_m3u_xtream_format' => 'boolean',
        'aiostreams_integration_id' => 'integer',
        'hdhr_enabled' => 'boolean',
        'm3u_enabled' => 'boolean',
        'xapi_enabled' => 'boolean',
        'xmltv_enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function streamProfile(): BelongsTo
    {
        return $this->belongsTo(StreamProfile::class);
    }

    /**
     * The AIOStreams integration that this merged playlist is permitted to access.
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

    public function playlists(): BelongsToMany
    {
        return $this->belongsToMany(Playlist::class, 'merged_playlist_playlist')
            ->using(MergedPlaylistPivot::class)
            ->withPivot(['include_live', 'include_vod', 'include_series', 'only_unmatched']);
    }

    /**
     * IDs of the source playlists, optionally limited to those contributing a
     * content type ('live', 'vod' or 'series') via the per-source pivot toggles.
     * Ordered by playlist id: without an ORDER BY, Postgres returns pivot rows in
     * heap order, which changes as soon as a row is updated.
     *
     * @return array<int>
     */
    public function sourcePlaylistIds(?string $contentType = null): array
    {
        $sources = $this->playlists();

        // Not when(): its callback receives the Eloquent builder, on which wherePivot()
        // degrades to a dynamic where on a column literally named "pivot".
        if ($contentType !== null) {
            $sources->wherePivot("include_{$contentType}", true);
        }

        return $sources->orderBy('playlists.id')->pluck('playlists.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Source playlists that expose an Xtream API URL, shaped for the PlaylistAlias
     * credential-swap form. Mirrors CustomPlaylist::getSourcePlaylistsForAlias().
     *
     * @return array<int, array{id: int, name: string, url: string}>
     */
    public function getSourcePlaylistsForAlias(): array
    {
        return $this->playlists()
            ->get()
            ->map(function (Playlist $playlist): array {
                $url = $playlist->xtream_config['url'] ?? null;

                return [
                    'id' => $playlist->id,
                    'name' => $playlist->name,
                    'url' => $url ? rtrim($url, '/') : null,
                ];
            })
            ->filter(fn (array $config): bool => $config['url'] !== null)
            ->values()
            ->toArray();
    }

    public function channels(): HasManyThrough
    {
        return $this->hasManyThrough(
            Channel::class,
            MergedPlaylistPivot::class,
            'merged_playlist_id',
            'playlist_id',
            'id',
            'playlist_id'
        )->where(function ($query) {
            // Per-source content-type toggle: a channel only comes through if its
            // source playlist is configured to contribute that content type.
            $query->where(function ($q) {
                $q->where('channels.is_vod', false)->where('merged_playlist_playlist.include_live', true);
            })->orWhere(function ($q) {
                $q->where('channels.is_vod', true)->where('merged_playlist_playlist.include_vod', true);
            });
        })->where(self::onlyUnmatchedChannels());
    }

    /**
     * Movie rule for only_unmatched sources, shared with merged-playlist aliases (any query
     * joined through merged_playlist_playlist): a local movie is hidden when an enabled
     * provider movie in the same merge already serves it, either through a match row on
     * this movie or on another local copy with the same tmdb_id. The copy check starts from
     * the copies (playlist_id + tmdb_id index) so it never scans every match row per item.
     */
    public static function onlyUnmatchedChannels(): Closure
    {
        return fn ($query) => $query
            ->where('merged_playlist_playlist.only_unmatched', false)
            ->orWhere(fn ($unmatched) => $unmatched
                ->whereNotExists(fn ($matches) => self::enabledMovieMatches($matches)
                    ->whereColumn('media_source_matches.media_channel_id', 'channels.id'))
                ->whereNotExists(fn ($copies) => $copies->selectRaw('1')
                    ->from('channels as copies')
                    ->whereIn('copies.playlist_id', self::mergeSourceIds())
                    ->whereColumn('copies.tmdb_id', 'channels.tmdb_id')
                    ->whereColumn('copies.id', '!=', 'channels.id')
                    ->whereExists(fn ($matches) => self::enabledMovieMatches($matches)
                        ->whereColumn('media_source_matches.media_channel_id', 'copies.id')
                        ->whereColumn('media_source_matches.playlist_id', '!=', 'channels.playlist_id'))));
    }

    /**
     * Which of a source's series rows (series, seasons or episodes, per [$table]) a merge
     * includes, shared with merged-playlist aliases: everything when the source contributes
     * series, and otherwise still the series the DVR created from its recordings. The
     * include_series toggle is for provider series; a DVR recording stays reachable through
     * the merge (see DvrAccessScope::settingIdsForPlaylist()) for as long as its source is
     * not fully excluded, so its library series follows the same rule.
     */
    public static function includesSeriesFromSource(string $table): Closure
    {
        return fn ($query) => $query
            ->where('merged_playlist_playlist.include_series', true)
            ->orWhere(fn ($dvr) => $dvr
                ->where("{$table}.import_batch_no", 'dvr')
                ->where(fn ($included) => $included
                    ->where('merged_playlist_playlist.include_live', true)
                    ->orWhere('merged_playlist_playlist.include_vod', true)));
    }

    /**
     * Series rule for only_unmatched sources, shared with merged-playlist aliases: a local
     * series (with its seasons and episodes) is hidden once any of its episodes, or an episode
     * of another local copy with the same tmdb_id, is matched to an enabled provider episode of
     * an enabled provider series in the same merge.
     */
    public static function onlyUnmatchedSeries(string $table): Closure
    {
        $seriesIdColumn = $table === 'series' ? 'series.id' : "{$table}.series_id";

        return fn ($query) => $query
            ->where('merged_playlist_playlist.only_unmatched', false)
            ->orWhere(fn ($unmatched) => $unmatched
                ->whereNotExists(fn ($matches) => self::enabledEpisodeMatches($matches)
                    ->whereColumn('matched_episodes.series_id', $seriesIdColumn))
                ->whereNotExists(fn ($copies) => $copies->selectRaw('1')
                    ->from('series as copies')
                    ->join('series as own_series', 'own_series.tmdb_id', '=', 'copies.tmdb_id')
                    ->whereColumn('own_series.id', $seriesIdColumn)
                    ->whereIn('copies.playlist_id', self::mergeSourceIds())
                    ->whereColumn('copies.id', '!=', $seriesIdColumn)
                    ->whereExists(fn ($matches) => self::enabledEpisodeMatches($matches)
                        ->whereColumn('matched_episodes.series_id', 'copies.id')
                        ->whereColumn('media_source_matches.playlist_id', '!=', "{$table}.playlist_id"))));
    }

    /**
     * Match rows whose provider movie is enabled and whose provider contributes VOD to the merge.
     */
    private static function enabledMovieMatches(QueryBuilder $matches): QueryBuilder
    {
        return self::scopeToMergeProviders($matches->selectRaw('1')
            ->from('media_source_matches')
            ->join('channels as provider_channels', 'provider_channels.id', '=', 'media_source_matches.channel_id')
            ->where('provider_channels.enabled', true), 'include_vod');
    }

    /**
     * Match rows (joined to the local matched_episodes) whose provider episode and series are
     * enabled and whose provider contributes series to the merge.
     */
    private static function enabledEpisodeMatches(QueryBuilder $matches): QueryBuilder
    {
        return self::scopeToMergeProviders($matches->selectRaw('1')
            ->from('media_source_matches')
            ->join('episodes as matched_episodes', 'matched_episodes.id', '=', 'media_source_matches.media_episode_id')
            ->join('episodes as provider_episodes', 'provider_episodes.id', '=', 'media_source_matches.episode_id')
            ->join('series as provider_series', 'provider_series.id', '=', 'provider_episodes.series_id')
            ->where('provider_episodes.enabled', true)
            ->where('provider_series.enabled', true), 'include_series');
    }

    /**
     * Narrow a media_source_matches query to matches made by a playlist attached to this merge
     * that contributes the content type, so a local item is only hidden when that source
     * actually lists the provider title.
     */
    private static function scopeToMergeProviders(QueryBuilder $matches, string $includeColumn): QueryBuilder
    {
        return $matches->whereIn('media_source_matches.playlist_id', fn ($sources) => $sources
            ->select('merge_sources.playlist_id')
            ->from('merged_playlist_playlist as merge_sources')
            ->whereColumn('merge_sources.merged_playlist_id', 'merged_playlist_playlist.merged_playlist_id')
            ->where("merge_sources.{$includeColumn}", true));
    }

    /**
     * Playlist ids attached to the merge being queried.
     */
    private static function mergeSourceIds(): Closure
    {
        return fn ($sources) => $sources
            ->select('merge_copies.playlist_id')
            ->from('merged_playlist_playlist as merge_copies')
            ->whereColumn('merge_copies.merged_playlist_id', 'merged_playlist_playlist.merged_playlist_id');
    }

    public function groups(): HasManyThrough
    {
        return $this->hasManyThrough(
            Group::class,
            MergedPlaylistPivot::class,
            'merged_playlist_id',
            'playlist_id',
            'id',
            'playlist_id'
        );
    }

    public function enabled_channels(): HasManyThrough
    {
        return $this->channels()->where('enabled', true);
    }

    public function series(): HasManyThrough
    {
        return $this->hasManyThrough(
            Series::class,
            MergedPlaylistPivot::class,
            'merged_playlist_id',
            'playlist_id',
            'id',
            'playlist_id'
        )->where(self::includesSeriesFromSource('series'))
            ->where(self::onlyUnmatchedSeries('series'));
    }

    public function enabled_series(): HasManyThrough
    {
        return $this->series()->where('enabled', true);
    }

    public function seasons(): HasManyThrough
    {
        return $this->hasManyThrough(
            Season::class,
            MergedPlaylistPivot::class,
            'merged_playlist_id',
            'playlist_id',
            'id',
            'playlist_id'
        )->where(self::includesSeriesFromSource('seasons'))
            ->where(self::onlyUnmatchedSeries('seasons'));
    }

    public function episodes(): HasManyThrough
    {
        return $this->hasManyThrough(
            Episode::class,
            MergedPlaylistPivot::class,
            'merged_playlist_id',
            'playlist_id',
            'id',
            'playlist_id'
        )->where(self::includesSeriesFromSource('episodes'))
            ->where(self::onlyUnmatchedSeries('episodes'));
    }

    public function live_channels(): HasManyThrough
    {
        return $this->channels()
            ->where('is_vod', false);
    }

    public function enabled_live_channels(): HasManyThrough
    {
        return $this->live_channels()
            ->where('enabled', true);
    }

    public function vod_channels(): HasManyThrough
    {
        return $this->channels()
            ->where('is_vod', true);
    }

    public function enabled_vod_channels(): HasManyThrough
    {
        return $this->vod_channels()
            ->where('enabled', true);
    }

    public function playlistAuths(): MorphToMany
    {
        return $this->morphToMany(PlaylistAuth::class, 'authenticatable');
    }

    public function postProcesses(): MorphToMany
    {
        return $this->morphToMany(PostProcess::class, 'processable');
    }

    public function playlistViewers(): MorphMany
    {
        return $this->morphMany(PlaylistViewer::class, 'viewerable');
    }

    public function enableProxy(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value) {
                    // Check playlist user has access to proxy features
                    if (! $this->user?->canUseProxy()) {
                        return false;
                    }
                }

                return $value;
            }
        );
    }
}
