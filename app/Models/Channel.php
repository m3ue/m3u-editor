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
        'stream_stats' => 'array',
        'stream_stats_probed_at' => 'datetime',
        'probe_enabled' => 'boolean',
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

        // Always proxy the internal player so we can attempt to transcode the stream for better compatibility
        // Use internal (relative) URLs to prevent CORS and mixed-content issues
        [$url, $format] = $this->getProxyUrl(
            withFormat: true,
            profileFormat: $profile->format ?? null,
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
            'url' => $url,
            'format' => $profile->format ?? $format,
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
        $playlist = Playlist::find($this->playlist_id ?: $this->custom_playlist_id);
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
        $url .= '?'.http_build_query([
            'proxy' => 'true',
        ]);

        return $withFormat ? [$url, $channelFormat] : $url;
    }

    /**
     * Get the stream attributes.
     *
     * @var array
     */
    public function getStreamStatsAttribute(): array
    {
        // Prefer persisted stream_stats from database
        $persisted = $this->attributes['stream_stats'] ?? null;
        if ($persisted) {
            $decoded = is_string($persisted) ? json_decode($persisted, true) : $persisted;
            if (! empty($decoded)) {
                return $decoded;
            }
        }

        // Fall back to cache
        $stats = Cache::get("channel_stream_stats_{$this->id}");
        if ($stats !== null) {
            return $stats;
        }

        return [];
    }

    /**
     * Run ffprobe against this channel's stream URL and return parsed stats.
     *
     * @return array{streams: array<int, array{codec_type: string, codec_name: string, codec_long_name: ?string, profile: ?string, width: ?int, height: ?int, bit_rate: ?string, avg_frame_rate: ?string, display_aspect_ratio: ?string, sample_rate: ?string, channels: ?int, channel_layout: ?string, level: ?int, bits_per_raw_sample: ?string}>}
     */
    public function probeStreamStats(): array
    {
        try {
            $url = $this->url_custom ?? $this->url;
            if (empty($url)) {
                return [];
            }

            $process = new SymfonyProcess(['ffprobe', '-v', 'quiet', '-print_format', 'json', '-show_streams', $url]);
            $process->setTimeout(15);
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
                        ];
                    }
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
        foreach ($stats as $entry) {
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

            // Parse frame rate from "25/1" or "30000/1001" format
            $fps = $video['avg_frame_rate'] ?? null;
            if ($fps && str_contains($fps, '/')) {
                [$num, $den] = explode('/', $fps);
                $result['source_fps'] = $den > 0 ? round((float) $num / (float) $den, 2) : null;
            } else {
                $result['source_fps'] = $fps ? (float) $fps : null;
            }

            // Convert bps to kbps
            $bitRate = $video['bit_rate'] ?? null;
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
            $result['audio_language'] = null;
        }

        return $result;
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
