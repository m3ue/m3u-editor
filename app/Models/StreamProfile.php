<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StreamProfile extends Model
{
    use HasFactory;

    protected $casts = [
        'rules' => 'array',
        'else_stream_profile_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function elseStreamProfile(): BelongsTo
    {
        return $this->belongsTo(self::class, 'else_stream_profile_id');
    }

    public function playlists(): HasMany
    {
        return $this->hasMany(Playlist::class);
    }

    public function customPlaylists(): HasMany
    {
        return $this->hasMany(CustomPlaylist::class);
    }

    public function mergedPlaylists(): HasMany
    {
        return $this->hasMany(MergedPlaylist::class);
    }

    public function playlistAliases(): HasMany
    {
        return $this->hasMany(PlaylistAlias::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    /**
     * Whether this profile uses a resolver backend (streamlink or yt-dlp)
     * rather than FFmpeg for stream delivery.
     */
    public function isResolver(): bool
    {
        return in_array($this->backend, ['streamlink', 'ytdlp'], strict: true);
    }

    /**
     * Whether this profile is adaptive (rule-based) — it delegates to
     * another profile based on probed channel metadata. Adaptive profiles
     * carry no transcoder args of their own; they resolve to a concrete
     * transcoding profile at stream-start time via StreamProfileRuleEvaluator.
     */
    public function isAdaptive(): bool
    {
        return $this->backend === 'adaptive';
    }

    /**
     * Return all adaptive profiles (owned by the same user) that reference
     * this profile — either as a rule target or as the else fallback.
     * Used to guard against accidental deletion of profiles in use.
     *
     * @return Collection<int, self>
     */
    public function getReferencingAdaptiveProfiles(): Collection
    {
        return static::query()
            ->where('user_id', $this->user_id)
            ->where('backend', 'adaptive')
            ->where('id', '!=', $this->id)
            ->get(['id', 'name', 'rules', 'else_stream_profile_id'])
            ->filter(function (self $adaptive): bool {
                if ($adaptive->else_stream_profile_id === $this->id) {
                    return true;
                }

                foreach ($adaptive->rules ?? [] as $rule) {
                    if ((int) ($rule['stream_profile_id'] ?? 0) === $this->id) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    /**
     * Get template variables for FFmpeg profile rendering.
     * The 'args' field can store either:
     * 1. A full FFmpeg argument template string (e.g., "-c:v libx264 -preset faster...")
     * 2. JSON-encoded key-value pairs for predefined profile overrides
     *
     * This method attempts to parse as JSON first, falls back to empty array.
     *
     * @param  array  $additionalVars  Optional additional variables to merge
     * @return array Template variables as associative array
     */
    public function getTemplateVariables(array $additionalVars = []): array
    {
        $variables = [];

        // Merge with additional variables (additional vars take precedence)
        return array_merge($variables, $additionalVars);
    }

    /**
     * Get the profile identifier for FFmpeg API usage.
     * Only applicable when backend is 'ffmpeg'.
     *
     * @return string Profile template or name for m3u-proxy API
     */
    public function getProfileIdentifier(): string
    {
        return $this->args;
    }
}
