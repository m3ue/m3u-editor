<?php

namespace App\Models;

use App\Enums\ChannelLogoType;
use App\Enums\PlaylistSourceType;
use App\Jobs\FetchTmdbIds;
use App\Observers\ChannelObserver;
use App\Services\PlaylistService;
use App\Services\StreamProfileRuleEvaluator;
use App\Services\XtreamService;
use App\Settings\GeneralSettings;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
        'stream_profile_id' => 'integer',
        'extvlcopt' => 'array',
        'kodidrop' => 'array',
        'is_custom' => 'boolean',
        'is_vod' => 'boolean',
        'enable_proxy' => 'boolean',
        'tmdb_id' => 'integer',
        'tvdb_id' => 'integer',
        'imdb_id' => 'string',
        'info' => 'array',
        'movie_data' => 'array',
        'sync_settings' => 'array',
        'last_metadata_fetch' => 'datetime',
        'epg_map_enabled' => 'boolean',
        'logo_type' => ChannelLogoType::class,
        'sort' => 'decimal:4',
        'stream_stats' => 'array',
        'stream_stats_probed_at' => 'datetime',
        'probe_enabled' => 'boolean',
        'year' => 'integer',
        'edition' => 'string',
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
     * Resolve the channel's stream profile to a concrete transcoding profile,
     * unwrapping an adaptive profile (backend === 'adaptive') by evaluating
     * its rules against the channel's cached probe data. Use this anywhere
     * the profile is consumed for actual streaming; use $channel->streamProfile
     * when showing the user-assigned value (it may itself be adaptive).
     */
    public function getEffectiveStreamProfile(): ?StreamProfile
    {
        $profile = $this->relationLoaded('streamProfile')
            ? $this->streamProfile
            : $this->streamProfile()->first();

        return app(StreamProfileRuleEvaluator::class)
            ->unwrap($profile, $this->stream_stats);
    }

    /**
     * Determine whether the proxy is effectively enabled for this channel.
     * Returns true if either the channel itself or its parent playlist has proxy enabled.
     */
    public function isProxyEnabled(): bool
    {
        if ($this->enable_proxy) {
            return true;
        }

        if ($this->playlist_id !== null && ! $this->relationLoaded('playlist')) {
            $this->load('playlist');
        } elseif ($this->custom_playlist_id !== null && ! $this->relationLoaded('customPlaylist')) {
            $this->load('customPlaylist');
        }

        $playlist = $this->getEffectivePlaylist();

        return (bool) ($playlist?->enable_proxy ?? false);
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

    /**
     * The human-readable display title for the channel.
     * Prefers the custom EPG title, falls back to the raw EPG title,
     * then the custom stream name, then the raw stream name.
     */
    public function getDisplayTitleAttribute(): string
    {
        return $this->title_custom ?? $this->title ?? $this->name_custom ?? $this->name ?? '';
    }

    public function getFloatingPlayerAttributes(?string $username = null, ?string $password = null): array
    {
        $settings = app(GeneralSettings::class);

        // Channel-level profile takes priority; global in-app default is the fallback.
        // Playlist-level profiles are for external clients and are intentionally excluded here.
        $globalProfileId = $this->is_vod
            ? ($settings->default_vod_stream_profile_id ?? null)
            : ($settings->default_stream_profile_id ?? null);
        $profile = $this->relationLoaded('streamProfile')
            ? $this->streamProfile
            : $this->streamProfile()->first();
        $profile ??= ($globalProfileId ? StreamProfile::find($globalProfileId) : null);
        $profile = app(StreamProfileRuleEvaluator::class)->unwrap($profile, $this->stream_stats);

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

        return [
            'id' => $this->id,
            'stream_id' => $this->id,
            'content_type' => $this->is_vod ? 'vod' : 'live',
            'playlist_id' => $this->playlist_id,
            'title' => $this->name_custom ?? $this->name,
            'display_title' => $this->display_title,
            'url' => $url,
            'format' => $format,
            'type' => 'channel',
        ];
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
        $raw = $this->attributes['stream_stats'] ?? null;
        if ($raw) {
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            if (! empty($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Return stream_stats, probing via ffprobe and persisting to the database if not yet populated.
     */
    public function ensureStreamStats(): array
    {
        $stats = $this->stream_stats;

        if (! empty($stats)) {
            return $stats;
        }

        $stats = $this->probeStreamStats();

        if (! empty($stats)) {
            $this->updateQuietly([
                'stream_stats' => $stats,
                'stream_stats_probed_at' => now(),
            ]);
        }

        return $stats;
    }

    /**
     * Run ffprobe against this channel's stream URL and return parsed stats.
     *
     * Returns a flat list of entries, each with one of two shapes:
     *   - Stream entry:  ['stream' => ['codec_type' => string, 'codec_name' => string, ...]]
     *   - Format entry:  ['format' => ['bit_rate' => string]]  (appended once when available)
     *
     * The format entry carries the container-level bit_rate from `-show_format`. It is used
     * as a fallback video bitrate for live MPEG-TS streams where ffprobe cannot determine
     * a per-stream bit_rate. See getEmbyStreamStats() for the derivation logic.
     *
     * @return list<array{stream: array{codec_type: string, codec_name: string, codec_long_name: ?string, profile: ?string, width: ?int, height: ?int, bit_rate: ?string, avg_frame_rate: ?string, display_aspect_ratio: ?string, sample_rate: ?string, channels: ?int, channel_layout: ?string, level: ?int, bits_per_raw_sample: ?string, refs: ?int, tags: array<string, string>}}|array{format: array{bit_rate: string}}>
     */
    public function probeStreamStats(int $timeout = 15): array
    {
        try {
            $url = $this->url_custom ?? $this->url;
            if (empty($url)) {
                return [];
            }

            $process = new SymfonyProcess(['ffprobe', '-v', 'quiet', '-print_format', 'json', '-show_streams', '-show_format', $url]);
            $process->setTimeout($timeout);
            $process->run();

            if ($process->getExitCode() !== 0) {
                Log::error("Error running ffprobe for channel \"{$this->title}\": {$process->getErrorOutput()}");

                return [];
            }

            $output = $process->getOutput();
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
                            'level' => $stream['level'] ?? null,
                            'width' => $stream['width'] ?? null,
                            'height' => $stream['height'] ?? null,
                            'bit_rate' => $stream['bit_rate'] ?? null,
                            'avg_frame_rate' => $stream['avg_frame_rate'] ?? null,
                            'display_aspect_ratio' => $stream['display_aspect_ratio'] ?? null,
                            'sample_rate' => $stream['sample_rate'] ?? null,
                            'channels' => $stream['channels'] ?? null,
                            'channel_layout' => $stream['channel_layout'] ?? null,
                            'bits_per_raw_sample' => $stream['bits_per_raw_sample'] ?? null,
                            'refs' => $stream['refs'] ?? null,
                            'tags' => $stream['tags'] ?? [],
                        ];
                    }
                }

                // MPEG-TS live streams typically don't expose a per-stream video
                // bit_rate (no CBR container, unknown duration). Capture the
                // container-level bit_rate from -show_format so we can derive a
                // sensible video bitrate fallback in getEmbyStreamStats().
                $formatBitRate = $json['format']['bit_rate'] ?? null;
                if ($formatBitRate !== null) {
                    $streamStats[] = ['format' => ['bit_rate' => $formatBitRate]];
                }

                return $streamStats;
            }
        } catch (Exception $e) {
            Log::error("Error running ffprobe for channel \"{$this->title}\": {$e->getMessage()}");
        }

        return [];
    }

    /**
     * Build stream_stats in the format expected by emby-xtream (Dispatcharr-compatible).
     *
     * @return array{resolution: ?string, video_codec: ?string, video_profile: ?string, video_level: ?int, video_bit_depth: ?int, source_fps: ?float, ffmpeg_output_bitrate: ?float, audio_codec: ?string, audio_channels: ?string, sample_rate: ?int, audio_bitrate: ?float, audio_language: ?string}
     */
    public function getEmbyStreamStats(): array
    {
        $stats = $this->stream_stats;
        if (empty($stats)) {
            return [];
        }

        $video = null;
        $audio = null;
        $formatBitRate = null;
        foreach ($stats as $entry) {
            if (isset($entry['format']['bit_rate'])) {
                $formatBitRate = $entry['format']['bit_rate'];

                continue;
            }
            $stream = $entry['stream'] ?? $entry;
            if (($stream['codec_type'] ?? '') === 'video' && ! $video) {
                $video = $stream;
            }
            if (($stream['codec_type'] ?? '') === 'audio' && ! $audio) {
                $audio = $stream;
            }
        }

        if (! $video && ! $audio) {
            return [];
        }

        $result = [];

        if ($video) {
            $width = $video['width'] ?? null;
            $height = $video['height'] ?? null;
            $result['resolution'] = ($width && $height) ? "{$width}x{$height}" : null;
            $result['video_codec'] = $video['codec_name'] ?? null;
            $result['video_profile'] = $video['profile'] ?? null;
            $result['video_level'] = isset($video['level']) ? (int) $video['level'] : null;
            $result['video_bit_depth'] = isset($video['bits_per_raw_sample']) ? (int) $video['bits_per_raw_sample'] : 8;
            $result['video_ref_frames'] = isset($video['refs']) ? (int) $video['refs'] : null;

            // Parse frame rate from "25/1" or "30000/1001" format
            $fps = $video['avg_frame_rate'] ?? null;
            if ($fps && str_contains($fps, '/')) {
                [$num, $den] = explode('/', $fps);
                $result['source_fps'] = $den > 0 ? round((float) $num / (float) $den, 2) : null;
            } else {
                $result['source_fps'] = $fps ? (float) $fps : null;
            }

            // Convert bps to kbps. For MPEG-TS live streams ffprobe usually
            // reports no per-stream bit_rate on the video elementary stream
            // (no CBR container, unknown duration). Fall back to
            // container_bitrate - audio_bitrate, which is a tight upper bound
            // for the video bitrate on a typical 1 video + 1 audio TS mux.
            // NOTE: only the first audio track's bitrate is subtracted, so streams
            // with multiple audio tracks will produce a slightly overstated value.
            $bitRate = $video['bit_rate'] ?? null;
            if ($bitRate === null && $formatBitRate !== null) {
                $audioBps = isset($audio['bit_rate']) ? (float) $audio['bit_rate'] : 0.0;
                $derived = (float) $formatBitRate - $audioBps;
                if ($derived > 0) {
                    $bitRate = $derived;
                }
            }
            $result['ffmpeg_output_bitrate'] = $bitRate ? round((float) $bitRate / 1000, 1) : null;
        }

        if ($audio) {
            $result['audio_codec'] = $audio['codec_name'] ?? null;

            // Map channel count to layout string
            $channels = $audio['channels'] ?? null;
            if ($channels) {
                $result['audio_channels'] = match ((int) $channels) {
                    1 => 'mono',
                    2 => 'stereo',
                    6 => '5.1',
                    8 => '7.1',
                    default => (string) $channels,
                };
            } else {
                $result['audio_channels'] = $audio['channel_layout'] ?? null;
            }

            $result['sample_rate'] = isset($audio['sample_rate']) ? (int) $audio['sample_rate'] : null;

            // Convert bps to kbps
            $audioBitRate = $audio['bit_rate'] ?? null;
            $result['audio_bitrate'] = $audioBitRate ? round((float) $audioBitRate / 1000, 1) : null;

            $tags = $audio['tags'] ?? [];
            $result['audio_language'] = $tags['language'] ?? null;
        }

        return $result;
    }

    /**
     * Build a display-friendly stream_stats shape for the Technical Details infolist panel.
     *
     * @return array{
     *     compact: array{
     *         resolution: ?string,
     *         source_fps: ?float,
     *         video_codec_display: ?string,
     *         ffmpeg_output_bitrate: ?float,
     *         audio_codec: ?string,
     *         audio_channels: ?string,
     *         audio_bitrate: ?float,
     *         audio_language: ?string
     *     },
     *     advanced: array{
     *         video: array{codec_long_name: ?string, level: ?int, bit_depth: ?int, ref_frames: ?int, display_aspect_ratio: ?string},
     *         audio: array{sample_rate: ?int, codec_long_name: ?string},
     *         all_streams: ?array<int, array{index: ?int, type: ?string, codec: ?string, lang: ?string}>,
     *         tags: ?array<string, string>
     *     }
     * }
     */
    public function getStreamStatsForDisplay(): array
    {
        return once(function (): array {
            $emby = $this->getEmbyStreamStats();
            $stats = $this->stream_stats ?? [];

            $video = null;
            $audio = null;
            $allStreams = [];

            foreach ($stats as $index => $entry) {
                $stream = $entry['stream'] ?? $entry;
                $type = $stream['codec_type'] ?? null;

                $allStreams[] = [
                    'index' => $stream['index'] ?? $index,
                    'type' => $type,
                    'codec' => $stream['codec_name'] ?? null,
                    'lang' => $stream['tags']['language'] ?? null,
                ];

                if ($type === 'video' && $video === null) {
                    $video = $stream;
                } elseif ($type === 'audio' && $audio === null) {
                    $audio = $stream;
                }
            }

            $videoCodecDisplay = null;
            if (! empty($emby['video_codec'])) {
                $videoCodecDisplay = $emby['video_codec'];
                if (! empty($emby['video_profile'])) {
                    $videoCodecDisplay .= " ({$emby['video_profile']})";
                }
            }

            $videoTags = ! empty($video['tags']) ? $video['tags'] : null;

            return [
                'compact' => [
                    'resolution' => $emby['resolution'] ?? null,
                    'source_fps' => $emby['source_fps'] ?? null,
                    'video_codec_display' => $videoCodecDisplay,
                    'ffmpeg_output_bitrate' => $emby['ffmpeg_output_bitrate'] ?? null,
                    'audio_codec' => $emby['audio_codec'] ?? null,
                    'audio_channels' => $emby['audio_channels'] ?? null,
                    'audio_bitrate' => $emby['audio_bitrate'] ?? null,
                    'audio_language' => $emby['audio_language'] ?? null,
                ],
                'advanced' => [
                    'video' => [
                        'codec_long_name' => $video['codec_long_name'] ?? null,
                        'level' => isset($video['level']) ? (int) $video['level'] : null,
                        'bit_depth' => isset($video['bits_per_raw_sample']) ? (int) $video['bits_per_raw_sample'] : null,
                        'ref_frames' => isset($video['refs']) ? (int) $video['refs'] : null,
                        'display_aspect_ratio' => $video['display_aspect_ratio'] ?? null,
                    ],
                    'audio' => [
                        'sample_rate' => isset($audio['sample_rate']) ? (int) $audio['sample_rate'] : null,
                        'codec_long_name' => $audio['codec_long_name'] ?? null,
                    ],
                    'all_streams' => count($allStreams) > 2 ? $allStreams : null,
                    'tags' => $videoTags,
                ],
            ];
        });
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

        // Skip the provider call if data is still fresh (unless a forced refresh is requested).
        $isFresh = ! $refresh && $this->last_metadata_fetch;

        try {
            if (! $isFresh) {
                $playlist = $this->playlist;

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
            }

            if (! $skipTmdb && $this->enabled) {
                $settings = app(GeneralSettings::class);
                if ($settings->tmdb_auto_lookup_on_import) {
                    dispatch(new FetchTmdbIds(
                        vodChannelIds: [$this->id],
                        overwriteExisting: $refresh,
                        sendCompletionNotification: false,
                    ))->afterCommit();
                }
            }

            return true;
        } catch (Exception $e) {
            Log::error('Failed to fetch metadata for VOD '.$this->id, ['exception' => $e]);
        }

        return false;
    }

    public function getTmdbId(): ?int
    {
        $id = $this->tmdb_id
            ?? $this->info['tmdb_id']
            ?? $this->info['tmdb']
            ?? $this->movie_data['tmdb_id']
            ?? $this->movie_data['tmdb']
            ?? null;

        return $id !== null ? (int) $id : null;
    }

    public function getImdbId(): ?string
    {
        return $this->imdb_id
            ?? $this->info['imdb_id']
            ?? $this->info['imdb']
            ?? $this->movie_data['imdb_id']
            ?? $this->movie_data['imdb']
            ?? null;
    }

    public function hasMovieId(): bool
    {
        return $this->getTmdbId() !== null || $this->getImdbId() !== null;
    }

    public function scopeHasMovieId(Builder $query): Builder
    {
        $isPgsql = config('database.connections.'.config('database.default').'.driver') === 'pgsql';

        return $query->where(function (Builder $q) use ($isPgsql) {
            $q->whereNotNull('tmdb_id')
                ->orWhereNotNull('imdb_id');

            if ($isPgsql) {
                $q->orWhereRaw("info::jsonb ?? 'tmdb_id'")
                    ->orWhereRaw("info::jsonb ?? 'tmdb'")
                    ->orWhereRaw("movie_data::jsonb ?? 'tmdb_id'")
                    ->orWhereRaw("movie_data::jsonb ?? 'tmdb'")
                    ->orWhereRaw("info::jsonb ?? 'imdb_id'")
                    ->orWhereRaw("info::jsonb ?? 'imdb'")
                    ->orWhereRaw("movie_data::jsonb ?? 'imdb_id'")
                    ->orWhereRaw("movie_data::jsonb ?? 'imdb'");
            }
        });
    }

    public function scopeMissingMovieId(Builder $query): Builder
    {
        $query->whereNull('tmdb_id')->whereNull('imdb_id');

        if (config('database.connections.'.config('database.default').'.driver') !== 'pgsql') {
            return $query;
        }

        return $query->where(function (Builder $q) {
            $q->where(function (Builder $inner) {
                $inner->whereNull('info')
                    ->orWhere(function (Builder $i) {
                        $i->whereRaw("NOT (info::jsonb ?? 'tmdb_id')")
                            ->whereRaw("NOT (info::jsonb ?? 'tmdb')")
                            ->whereRaw("NOT (info::jsonb ?? 'imdb_id')")
                            ->whereRaw("NOT (info::jsonb ?? 'imdb')");
                    });
            })->where(function (Builder $inner) {
                $inner->whereNull('movie_data')
                    ->orWhere(function (Builder $i) {
                        $i->whereRaw("NOT (movie_data::jsonb ?? 'tmdb_id')")
                            ->whereRaw("NOT (movie_data::jsonb ?? 'tmdb')")
                            ->whereRaw("NOT (movie_data::jsonb ?? 'imdb_id')")
                            ->whereRaw("NOT (movie_data::jsonb ?? 'imdb')");
                    });
            });
        });
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
