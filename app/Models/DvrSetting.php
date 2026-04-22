<?php

namespace App\Models;

use App\Enums\DvrRecordingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DvrSetting extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'playlist_id',
        'user_id',
        'enabled',
        'use_proxy',
        'dvr_output_format',
        'storage_disk',
        'storage_path',
        'max_concurrent_recordings',
        'ffmpeg_path',
        'default_start_early_seconds',
        'default_end_late_seconds',
        'enable_metadata_enrichment',
        'tmdb_api_key',
        'global_disk_quota_gb',
        'retention_days',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'use_proxy' => 'boolean',
            'max_concurrent_recordings' => 'integer',
            'default_start_early_seconds' => 'integer',
            'default_end_late_seconds' => 'integer',
            'enable_metadata_enrichment' => 'boolean',
            'tmdb_api_key' => 'encrypted',
            'global_disk_quota_gb' => 'integer',
            'retention_days' => 'integer',
        ];
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recordingRules(): HasMany
    {
        return $this->hasMany(DvrRecordingRule::class);
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(DvrRecording::class);
    }

    /**
     * Resolve the effective start-early seconds for a recording rule.
     */
    public function resolveStartEarlySeconds(?int $ruleOverride): int
    {
        return $ruleOverride ?? $this->default_start_early_seconds;
    }

    /**
     * Resolve the effective end-late seconds for a recording rule.
     */
    public function resolveEndLateSeconds(?int $ruleOverride): int
    {
        return $ruleOverride ?? $this->default_end_late_seconds;
    }

    /**
     * Check if the DVR is at concurrent recording capacity.
     */
    public function isAtCapacity(): bool
    {
        return $this->recordings()
            ->whereIn('status', [DvrRecordingStatus::Recording->value, DvrRecordingStatus::PostProcessing->value])
            ->count() >= $this->max_concurrent_recordings;
    }

    /**
     * Get the resolved ffmpeg binary path.
     */
    public function getFfmpegPath(): string
    {
        return $this->ffmpeg_path ?: (string) config('dvr.ffmpeg_path', '/usr/bin/ffmpeg');
    }
}
