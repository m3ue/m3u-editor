<?php

namespace App\Models;

use App\Services\PlaylistService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process as SymfonyProcess;

class Episode extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'new' => 'boolean',
        'enabled' => 'boolean',
        'source_episode_id' => 'integer',
        'user_id' => 'integer',
        'playlist_id' => 'integer',
        'series_id' => 'integer',
        'season_id' => 'integer',
        'episode_num' => 'integer',
        'season' => 'integer',
        'tmdb_id' => 'integer',
        'info' => 'array',
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
     * Get the effective playlist (currently only the main playlist is used)
     * This method returns the playlist that should be used for configuration
     */
    public function getEffectivePlaylist()
    {
        return $this->playlist;
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /**
     * Get all STRM file mappings for this episode
     */
    public function strmFileMappings(): MorphMany
    {
        return $this->morphMany(StrmFileMapping::class, 'syncable');
    }

    public function getFloatingPlayerAttributes(?string $username = null, ?string $password = null): array
    {
        $settings = app(GeneralSettings::class);

        // For episodes, prefer the VOD default profile first
        $profileId = $settings->default_vod_stream_profile_id ?? null;
        $profile = $profileId ? StreamProfile::find($profileId) : null;

        // Use the Xtream URL structure to preserve auth (username/password in URL).
        // Append ?player=true so XtreamStreamController routes this to the player
        // endpoint that applies the in-app transcoding profile.
        [$url, $episodeFormat] = $this->getProxyUrl(
            withFormat: true,
            profileFormat: $profile->format ?? null,
            username: $username,
            password: $password,
            internal: true
        );

        // When no transcoding profile is set, the proxy delivers raw bytes (direct proxy),
        // not an HLS manifest. If the URL-derived format is m3u8/ts but we have no profile,
        // use the actual container extension so the player picks the correct playback engine
        // (native for mkv/mp4/etc. instead of HLS.js which would fail on raw video data).
        $playerFormat = $episodeFormat;
        if (! $profile && in_array($episodeFormat, ['m3u8', 'ts'], true)) {
            $playerFormat = $this->container_extension ?? 'mkv';
        }

        return [
            'id' => 'episode-'.$this->id,
            'stream_id' => $this->id,
            'content_type' => 'episode',
            'playlist_id' => $this->playlist_id,
            'series_id' => $this->series_id,
            'season_number' => $this->season,
            'title' => $this->title,
            'url' => $url,
            'format' => $profile->format ?? $playerFormat,
            'type' => 'episode',
        ];
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var string|array
     */
    public function getProxyUrl(?bool $withFormat = false, ?string $profileFormat = null, ?string $username = null, ?string $password = null, bool $internal = false)
    {
        // Load the effective playlist to determine proxy settings and get UUID for authentication
        $playlist = Playlist::find($this->playlist_id);
        $user = $this->user;
        $originalUrl = $this->url;

        // Extract the filename from the URL to determine the format (extension)
        $filename = parse_url($originalUrl, PHP_URL_PATH);

        // Determine the channel format based on URL or container extension
        if (Str::endsWith($filename, '.m3u8')) {
            $episodeFormat = 'm3u8';
        } elseif (Str::endsWith($filename, '.ts')) {
            $episodeFormat = 'ts';
        } else {
            if ($playlist->xtream ?? false) {
                $episodeFormat = $playlist->xtream_config['output'] ?? 'mkv'; // Default to 'mkv' if not set
            } else {
                $episodeFormat = $this->container_extension ?? 'mkv';
            }
        }

        // If a specific format is provided (e.g. from a StreamProfile), use that instead of the detected format
        if ($profileFormat) {
            $episodeFormat = $profileFormat;
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
        $path = "/series/{$username}/{$password}/".$this->id.'.'.$episodeFormat;

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

        return $withFormat ? [$url, $episodeFormat] : $url;
    }

    /**
     * Get the stream attributes.
     *
     * @var array
     */
    public function getStreamStatsAttribute(): array
    {
        try {
            $url = $this->url;
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
                Log::error("Error running ffprobe for episode \"{$this->title}\": {$errors}");

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

                return $streamStats;
            }
        } catch (Exception $e) {
            Log::error("Error running ffprobe for episode \"{$this->title}\": {$e->getMessage()}");
        }

        return [];
    }

    /**
     * Get the added attribute with safe parsing
     */
    public function getAddedAttribute($value)
    {
        if (! $value) {
            return null;
        }

        try {
            // If it's a timestamp string, parse it
            if (is_numeric($value)) {
                return Carbon::createFromTimestamp($value);
            }

            // Try to parse as a regular date/time string
            return Carbon::parse($value);
        } catch (Exception $e) {
            // If parsing fails, return null
            return null;
        }
    }
}
