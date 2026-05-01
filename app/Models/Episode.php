<?php

namespace App\Models;

use App\Services\PlaylistService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

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
        'stream_stats' => 'array',
        'stream_stats_probed_at' => 'datetime',
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

    public function serie(): BelongsTo
    {
        return $this->belongsTo(Series::class, 'series_id');
    }

    /**
     * Get all STRM file mappings for this episode
     */
    public function strmFileMappings(): MorphMany
    {
        return $this->morphMany(StrmFileMapping::class, 'syncable');
    }

    /**
     * The human-readable display title for the episode.
     * For episodes the title is the canonical display title.
     */
    public function getDisplayTitleAttribute(): string
    {
        return $this->title ?? '';
    }

    public function getEpisodeNumberAttribute(): int
    {
        return (int) ($this->episode_num ?? 0);
    }

    public function getFormattedEpisodeNumberAttribute(): string
    {
        return sprintf('S%02dE%02d', (int) ($this->season ?? 0), (int) ($this->episode_num ?? 0));
    }

    public function scopeForSeason(Builder $query, int $seasonId): Builder
    {
        return $query->where('season_id', $seasonId);
    }

    public function getFloatingPlayerAttributes(?string $username = null, ?string $password = null): array
    {
        $settings = app(GeneralSettings::class);

        // For episodes, prefer the VOD default profile first
        $profileId = $settings->default_vod_stream_profile_id ?? null;
        $profile = $profileId ? StreamProfile::find($profileId) : null;

        // When no transcoding profile is set, the proxy delivers raw bytes (direct proxy),
        // not an HLS manifest. Use the actual container extension for both the URL path
        // and player format so the browser's <video> element can handle the content correctly.
        // The Xtream route accepts any format via {format?}, so this is safe for routing.
        $internalFormat = null;
        if (! $profile) {
            $internalFormat = $this->container_extension ?? 'mkv';
        }

        // Use the Xtream URL structure to preserve auth (username/password in URL).
        // Append ?player=true so XtreamStreamController routes this to the player
        // endpoint that applies the in-app transcoding profile.
        [$url, $episodeFormat] = $this->getProxyUrl(
            withFormat: true,
            profileFormat: $profile->format ?? $internalFormat,
            username: $username,
            password: $password,
            internal: true
        );

        return [
            'id' => 'episode-'.$this->id,
            'stream_id' => $this->id,
            'content_type' => 'episode',
            'playlist_id' => $this->playlist_id,
            'series_id' => $this->series_id,
            'season_number' => $this->season,
            'title' => $this->title,
            'display_title' => $this->display_title,
            'url' => $url,
            'format' => $episodeFormat,
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

    /**
     * Ensure stream stats are available, probing if necessary.
     */
    public function ensureStreamStats(): array
    {
        if (! empty($this->stream_stats) && ! empty($this->stream_stats['probed'])) {
            return $this->stream_stats;
        }

        $stats = $this->probeStreamStats();

        if (! empty($stats)) {
            $this->stream_stats = $stats;
            $this->stream_stats_probed_at = now();
            $this->saveQuietly();
        }

        return $stats;
    }

    /**
     * Probe the episode stream URL with ffprobe and return structured stats.
     */
    public function probeStreamStats(int $timeout = 15): array
    {
        $url = $this->url;

        if (empty($url)) {
            return [];
        }

        $ffprobePath = config('services.ffprobe.path', 'ffprobe');

        $command = sprintf(
            '%s -v quiet -print_format json -show_format -show_streams -timeout %d %s 2>&1',
            escapeshellcmd($ffprobePath),
            $timeout * 1000000, // ffprobe timeout is in microseconds
            escapeshellarg($url)
        );

        $output = shell_exec($command);
        $data = json_decode($output, true);

        if (! is_array($data)) {
            return [];
        }

        $stats = [
            'probed' => true,
            'probed_at' => now()->toIso8601String(),
            'url' => $url,
        ];

        $videoStream = null;
        $audioStream = null;

        foreach ($data['streams'] ?? [] as $stream) {
            if ($stream['codec_type'] === 'video' && $videoStream === null) {
                $videoStream = $stream;
            }
            if ($stream['codec_type'] === 'audio' && $audioStream === null) {
                $audioStream = $stream;
            }
        }

        if ($videoStream) {
            $width = $videoStream['width'] ?? 0;
            $height = $videoStream['height'] ?? 0;
            $stats['video'] = [
                'codec' => $videoStream['codec_name'] ?? null,
                'width' => $width,
                'height' => $height,
                'bitrate' => isset($videoStream['bit_rate']) ? (int) $videoStream['bit_rate'] : null,
                'framerate' => $videoStream['r_frame_rate'] ?? null,
                'profile' => $videoStream['profile'] ?? null,
                'pix_fmt' => $videoStream['pix_fmt'] ?? null,
            ];

            $stats['flat'] = array_filter([
                $videoStream['codec_name'] ?? null,
                $width && $height ? "{$width}x{$height}" : null,
                isset($videoStream['bit_rate']) ? round((int) $videoStream['bit_rate'] / 1000).'k' : null,
            ]);
        }

        if ($audioStream) {
            $stats['audio'] = [
                'codec' => $audioStream['codec_name'] ?? null,
                'channels' => $audioStream['channels'] ?? null,
                'sample_rate' => $audioStream['sample_rate'] ?? null,
                'bitrate' => isset($audioStream['bit_rate']) ? (int) $audioStream['bit_rate'] : null,
                'language' => $audioStream['tags']['language'] ?? null,
            ];
        }

        $stats['format'] = [
            'duration' => $data['format']['duration'] ?? null,
            'bitrate' => isset($data['format']['bit_rate']) ? (int) $data['format']['bit_rate'] : null,
            'format_name' => $data['format']['format_name'] ?? null,
        ];

        return $stats;
    }
}
