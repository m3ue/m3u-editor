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
        'max_concurrent_recordings',
        'default_start_early_seconds',
        'default_end_late_seconds',
        'enable_metadata_enrichment',
        'generate_nfo_files',
        'enable_comskip',
        'comskip_ini_path',
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
            'generate_nfo_files' => 'boolean',
            'enable_comskip' => 'boolean',
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
     *
     * Concurrent safety is provided at the scheduler level: DvrSchedulerTick implements
     * ShouldBeUnique, ensuring only one tick executes at a time. The three
     * createScheduledRecording paths additionally wrap their check + insert in a
     * DB::transaction, giving row-level isolation there.
     *
     * @param  int  $pendingInTick  Recordings already scheduled to start in this tick
     *                              but whose status flip to Recording has not yet
     *                              happened (the StartDvrRecording job is queued but
     *                              has not run). Counted toward the active total so
     *                              a single tick cannot dispatch more starts than
     *                              there are free slots.
     */
    public function isAtCapacity(int $pendingInTick = 0): bool
    {
        $active = $this->recordings()
            ->whereIn('status', [DvrRecordingStatus::Recording, DvrRecordingStatus::PostProcessing])
            ->count();

        return ($active + $pendingInTick) >= $this->max_concurrent_recordings;
    }
}
