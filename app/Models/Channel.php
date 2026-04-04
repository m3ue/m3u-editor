<?php

namespace App\Models;

use App\Enums\ChannelLogoType;
use App\Enums\PlaylistSourceType;
use App\Jobs\FetchTmdbIds;
use App\Observers\ChannelObserver;
use App\Services\PlaylistService;
use App\Services\XtreamService;
use App\Settings\GeneralSettings;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Spatie\Tags\HasTags;
use Symfony\Component\Process\Process as SymfonyProcess;

#[ObservedBy(ChannelObserver::class)]
class Channel extends Model
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
        'enabled' => 'boolean',
        'channel' => 'integer',
        'shift' => 'integer',
        'user_id' => 'integer',
        'playlist_id' => 'integer',
        'network_id' => 'integer',
        'group_id' => 'integer',
        'extvlcopt' => 'array',
        'kodidrop' => 'array',
        'is_custom' => 'boolean',
        'is_vod' => 'boolean',
        'tmdb_id' => 'integer',
        'tvdb_id' => 'integer',
        'info' => 'array',
        'movie_data' => 'array',
        'sync_settings' => 'array',
        'last_metadata_fetch' => 'datetime',
        'epg_map_enabled' => 'boolean',
        'logo_type' => ChannelLogoType::class,
        'sort' => 'decimal:4',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    /**
     * Get the network this channel represents (if any).
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    /**
     * Check if this channel is a network channel.
     */
    public function isNetworkChannel(): bool
    {
        return $this->network_id !== null;
    }

    /**
     * Get the effective playlist (either the main playlist or custom playlist)
     * This method returns the playlist that should be used for configuration
     */
    public function getEffectivePlaylist()
    {
        return $this->playlist ?? $this->customPlaylist;
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function streamFileSetting(): BelongsTo
    {
        return $this->belongsTo(StreamFileSetting::class);
    }

    public function epgChannel(): BelongsTo
    {
        return $this->belongsTo(EpgChannel::class)
            ->withoutEagerLoads();
    }

    public function customPlaylist(): BelongsTo
    {
        return $this->belongsTo(CustomPlaylist::class);
    }

    public function customPlaylists(): BelongsToMany
    {
        return $this->belongsToMany(CustomPlaylist::class, 'channel_custom_playlist');
    }

    public function failovers()
    {
        return $this->hasMany(ChannelFailover::class, 'channel_id');
    }

    /**
     * Get all STRM file mappings for this channel
     */
    public function strmFileMappings(): MorphMany
    {
        return $this->morphMany(StrmFileMapping::class, 'syncable');
    }

    public function failoverChannels(): HasManyThrough
    {
        return $this->hasManyThrough(
            Channel::class, // Deploy
            ChannelFailover::class, // Environment
            'channel_id', // Foreign key on the environments table...
            'id', // Foreign key on the deployments table...
            'id', // Local key on the projects table...
            'channel_failover_id' // Local key on the environments table...
        )->orderBy('channel_failovers.sort');
    }

    public function getFloatingPlayerAttributes(?string $username = null, ?string $password = null): array
    {
        $settings = app(GeneralSettings::class);

        if ($this->is_vod) {
            $profileId = $settings->default_vod_stream_profile_id ?? null;
        } else {
            $profileId = $settings->default_stream_profile_id ?? null;
        }
        $profile = $profileId ? StreamProfile::find($profileId) : null;

        // When no transcoding profile is set, the proxy delivers raw bytes (direct proxy),
        // not an HLS manifest. For VOD channels, use the actual container extension for both
        // the URL path and player format so the browser's <video> element handles the content.
        // Live channels are unaffected as m3u8/ts are valid direct-proxy formats.
        $internalFormat = null;
        if (! $profile && $this->is_vod) {
            $internalFormat = $this->container_extension ?? 'mkv';
        }

        // Use the Xtream URL structure to preserve auth (username/password in URL).
        // Append ?player=true so XtreamStreamController routes this to the player
        // endpoint that applies the in-app transcoding profile.
        [$url, $format] = $this->getProxyUrl(
            withFormat: true,
            profileFormat: $profile->format ?? $internalFormat,
            username: $username,
            password: $password,
            internal: true
        );

        [$castUrl, $castFormat, $castUnavailableReason] = $this->getCastPlaybackAttributes($username, $password);

        return [
            'id' => $this->id,
            'stream_id' => $this->id,
            'content_type' => $this->is_vod ? 'vod' : 'live',
            'playlist_id' => $this->playlist_id,
            'title' => $this->name_custom ?? $this->name,
            'display_title' => $this->title_custom ?? $this->title ?? $this->name_custom ?? $this->name,
            'url' => $url,
            'format' => $format,
            'cast_url' => $castUrl,
            'cast_format' => $castFormat,
            'cast_unavailable_reason' => $castUnavailableReason,
            'type' => 'channel',
        ];
    }

    protected function getCastPlaybackAttributes(?string $username = null, ?string $password = null): array
    {
        $castRoute = $this->is_vod ? 'cast.stream.movie' : 'cast.stream.live';

        $playlist = $this->playlist;

        if (! $playlist?->uuid) {
            $playlist = $this->customPlaylist;
        }

        if (! $playlist?->uuid) {
            $playlist = Playlist::find($this->playlist_id ?: $this->custom_playlist_id);
        }

        // Chromecast requires HLS.  Casting is available when either:
        //  1. A global cast/player HLS transcoding profile is configured, OR
        //  2. The provider already serves HLS (channel URL ends in .m3u8)
        // Note: playlist-level output transcoding settings (stream_profile_id /
        // vod_stream_profile_id) are for external clients only and are not
        // considered here.
        if (! self::hasHlsProfileForCasting($this->is_vod ? 'vod' : 'live')) {
            $sourceUrl = $this->url_custom ?: ($this->url ?? '');
            $sourceIsHls = (bool) preg_match('/\.m3u8($|\?)/i', $sourceUrl);

            if (! $sourceIsHls) {
                return [null, null, 'No HLS transcoding profile configured'];
            }
        }

        if ($username && $password) {
            return [
                route($castRoute, [
                    'username' => $username,
                    'password' => $password,
                    'streamId' => $this->id,
                    'format' => 'm3u8',
                ]),
                'm3u8',
                null,
            ];
        }

        if ($playlist?->uuid) {
            return [
                route($castRoute, [
                    'username' => $this->user->name ?? 'admin',
                    'password' => $playlist->uuid,
                    'streamId' => $this->id,
                    'format' => 'm3u8',
                ]),
                'm3u8',
                null,
            ];
        }

        return [null, null, null];
    }

    /**
     * Check if an explicitly configured HLS profile is available for casting.
     * Only considers profiles assigned in cast or in-app player settings.
     *
     * @param  string|null  $contentType  'live', 'vod', or null (check both)
     */
    public static function hasHlsProfileForCasting(?string $contentType = null): bool
    {
        $settings = app(GeneralSettings::class);

        // Check cast-specific VOD profile, falling back to in-app VOD profile
        if ($contentType !== 'live') {
            $profileId = $settings->default_cast_vod_stream_profile_id
                ?? $settings->default_vod_stream_profile_id
                ?? null;

            if ($profileId) {
                $profile = StreamProfile::find($profileId);
                if ($profile && in_array(strtolower((string) $profile->format), ['hls', 'm3u8'], true)) {
                    return true;
                }
            }
        }

        // Check cast-specific live profile, falling back to in-app live profile
        if ($contentType !== 'vod') {
            $liveProfileId = $settings->default_cast_stream_profile_id
                ?? $settings->default_stream_profile_id
                ?? null;

            if ($liveProfileId) {
                $profile = StreamProfile::find($liveProfileId);
                if ($profile && in_array(strtolower((string) $profile->format), ['hls', 'm3u8'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if the channel has metadata.
     */
    public function getHasMetadataAttribute(): bool
    {
        // Check if the channel has metadata (info or movie_data)
        return ! empty($this->info) || ! empty($this->movie_data);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var string|array
     */
    public function getProxyUrl(?bool $withFormat = false, ?string $profileFormat = null, ?string $username = null, ?string $password = null, bool $internal = false)
    {
        // Load the effective playlist to determine proxy settings and get UUID for authentication
        $playlist = $this->playlist ?? $this->customPlaylist;
        $user = $this->user;

        // Without a playlist (and no explicit credentials), there's no auth context for a proxy URL
        if (! $playlist && ! ($username && $password)) {
            return $withFormat ? [null, null] : null;
        }

        $originalUrl = $this->url_custom ?? $this->url;

        // Extract the filename from the URL to determine the format (extension)
        $filename = parse_url($originalUrl, PHP_URL_PATH);

        // Determine the channel format based on URL or container extension
        if (Str::endsWith($filename, '.m3u8')) {
            $channelFormat = 'm3u8';
        } elseif (Str::endsWith($filename, '.ts')) {
            $channelFormat = 'ts';
        } else {
            if ($playlist->xtream ?? false) {
                $channelFormat = $playlist->xtream_config['output'] ?? 'ts'; // Default to 'ts' if not set
            } else {
                $channelFormat = $this->container_extension ?? 'ts';
            }
        }
        $urlPath = 'live';
        if ($this->is_vod) {
            $urlPath = 'movie';
            $channelFormat = $this->container_extension ?? $channelFormat ?? 'mkv';
        }

        // If a specific format is provided (e.g. from a StreamProfile), use that instead of the detected format
        if ($profileFormat) {
            $channelFormat = $profileFormat;
        }

        // Determine the username and password to use for proxy authentication
        if ($username && $password) {
            $username = urlencode($username);
            $password = urlencode($password);
        } else {
            $username = urlencode($user->name ?? 'admin');
            $password = urlencode($playlist->uuid);
        }

        // Build the proxy URL path
        $path = "/{$urlPath}/{$username}/{$password}/".$this->id.'.'.$channelFormat;

        // Use relative URL for internal (in-app) players to prevent CORS and mixed-content issues
        if ($internal) {
            $url = rtrim($path, '.');
        } else {
            $url = rtrim(PlaylistService::getBaseUrl($path), '.');
        }

        // Append query parameter so our Xtream Stream controller knows to proxy the stream regardless of playlist settings
        $queryArgs = [
            'proxy' => 'true',
        ];
        if ($internal) {
            $queryArgs['player'] = 'true';
        }
        $url .= '?'.http_build_query($queryArgs);

        return $withFormat ? [$url, $channelFormat] : $url;
    }

    /**
     * Get the stream attributes.
     *
     * @var array
     */
    public function getStreamStatsAttribute(): array
    {
        $stats = Cache::get("channel_stream_stats_{$this->id}");
        if ($stats !== null) {
            return $stats;
        }
        try {
            $url = $this->url_custom ?? $this->url;
            $process = new SymfonyProcess(['ffprobe', '-v', 'quiet', '-print_format', 'json', '-show_streams', $url]);
            $process->setTimeout(10);
            $output = '';
            $errors = '';
            $hasErrors = false;
            $process->run(
                function ($type, $buffer) use (&$output, &$hasErrors, &$errors) {
                    if ($type === SymfonyProcess::OUT) {
                        $output .= $buffer;
                    }
                    if ($type === SymfonyProcess::ERR) {
                        $hasErrors = true;
                        $errors .= $buffer;
                    }
                }
            );
            if ($hasErrors) {
                Log::error("Error running ffprobe for channel \"{$this->title}\": {$errors}");

                return [];
            }
            $json = json_decode($output, true);
            if (isset($json['streams']) && is_array($json['streams'])) {
                $streamStats = [];
                foreach ($json['streams'] as $stream) {
                    if (isset($stream['codec_name'])) {
                        $streamStats[]['stream'] = [
                            'codec_type' => $stream['codec_type'],
                            'codec_name' => $stream['codec_name'],
                            'codec_long_name' => $stream['codec_long_name'] ?? null,
                            'profile' => $stream['profile'] ?? null,
                            'width' => $stream['width'] ?? null,
                            'height' => $stream['height'] ?? null,
                            'bit_rate' => $stream['bit_rate'] ?? null,
                            'avg_frame_rate' => $stream['avg_frame_rate'] ?? null,
                            'display_aspect_ratio' => $stream['display_aspect_ratio'] ?? null,
                            'sample_rate' => $stream['sample_rate'] ?? null,
                            'channels' => $stream['channels'] ?? null,
                            'channel_layout' => $stream['channel_layout'] ?? null,
                        ];
                    }
                }

                // Cache the result for 5 minutes
                Cache::put("channel_stream_stats_{$this->id}", $streamStats, now()->addMinutes(5));

                return $streamStats;
            }
        } catch (Exception $e) {
            Log::error("Error running ffprobe for channel \"{$this->title}\": {$e->getMessage()}");
        }

        return [];
    }

    public function fetchMetadata($xtream = null, $refresh = false, bool $skipTmdb = false)
    {
        if (! $this->is_vod) {
            return false;
        }

        // Custom channels should not fetch metadata
        if ($this->is_custom) {
            // Return true to indicate that we "succeeded" in fetching metadata, even though we intentionally did not fetch anything
            return true;
        }

        try {
            $playlist = $this->playlist;

            // Get settings instance
            $settings = app(GeneralSettings::class);

            // For Xtream playlists, use XtreamService
            if (! $xtream) {
                if (! $playlist->xtream && $playlist->source_type !== PlaylistSourceType::Xtream) {
                    // Not an Xtream playlist and not Emby, no metadata source available
                    return false;
                }
                $xtream = XtreamService::make($playlist);
            }

            if (! $xtream) {
                Notification::make()
                    ->danger()
                    ->title('VOD metadata sync failed')
                    ->body('Unable to connect to Xtream API provider to get VOD info, unable to fetch metadata.')
                    ->broadcast($playlist->user)
                    ->sendToDatabase($playlist->user);

                return false;
            }

            $movieData = $xtream->getVodInfo($this->source_id, timeout: 60);
            $releaseDate = $movieData['info']['release_date'] ?? null;
            $releaseDateAlt = $movieData['info']['releasedate'] ?? null;
            $year = $this->year;
            if (! $releaseDate && $releaseDateAlt) {
                // Make sure base release_date is always set
                $movieData['info']['release_date'] = $releaseDateAlt;
            }
            if ($releaseDate || $releaseDateAlt) {
                // If either data is set, and year is not set, update it
                $dateToParse = $releaseDate ?? $releaseDateAlt;
                $year = null;
                try {
                    $date = new \DateTime($dateToParse);
                    $year = (int) $date->format('Y');
                } catch (Exception $e) {
                    Log::warning("Unable to parse release date \"{$dateToParse}\" for VOD {$this->id}");
                }
            }
            $update = [
                'year' => $year,
                'info' => $movieData['info'] ?? null,
                'movie_data' => $movieData['movie_data'] ?? null,
                'last_metadata_fetch' => now(),
            ];

            $this->update($update);

            if (! $skipTmdb && $settings->tmdb_auto_lookup_on_import && $this->enabled) {
                dispatch(new FetchTmdbIds(
                    vodChannelIds: [$this->id],
                    overwriteExisting: $refresh ?? false,
                    sendCompletionNotification: false,
                ))->afterCommit();
            }

            return true;
        } catch (Exception $e) {
            Log::error('Failed to fetch metadata for VOD '.$this->id, ['exception' => $e]);
        }

        return false;
    }

    /**
     * Get the custom group name for a specific custom playlist
     */
    public function getCustomGroupName(string $customPlaylistUuid): string
    {
        $tag = $this->tags()
            ->where('type', $customPlaylistUuid)
            ->first();

        return $tag ? $tag->getAttributeValue('name') : 'Uncategorized';
    }
}
