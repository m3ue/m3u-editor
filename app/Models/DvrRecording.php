<?php

namespace App\Models;

use App\Enums\DvrRecordingStatus;
use App\Enums\DvrRuleType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DvrRecording extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'proxy_network_id',
        'user_id',
        'playlist_auth_id',
        'dvr_setting_id',
        'dvr_recording_rule_id',
        'channel_id',
        'status',
        'user_cancelled',
        'attempt_count',
        'title',
        'series_key',
        'normalized_title',
        'subtitle',
        'description',
        'season',
        'episode',
        'scheduled_start',
        'scheduled_end',
        'actual_start',
        'actual_end',
        'duration_seconds',
        'file_path',
        'file_size_bytes',
        'stream_url',
        'metadata',
        'error_message',
        'post_processing_step',
        'programme_start',
        'programme_end',
        'epg_programme_data',
        'programme_uid',
        'pid',
        'temp_path',
        'temp_manifest_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DvrRecordingStatus::class,
            'user_cancelled' => 'boolean',
            'attempt_count' => 'integer',
            'season' => 'integer',
            'episode' => 'integer',
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'actual_start' => 'datetime',
            'actual_end' => 'datetime',
            'duration_seconds' => 'integer',
            'file_size_bytes' => 'integer',
            'metadata' => 'array',
            'programme_start' => 'datetime',
            'programme_end' => 'datetime',
            'epg_programme_data' => 'array',
            'pid' => 'integer',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (DvrRecording $recording): void {
            if (empty($recording->uuid)) {
                $recording->uuid = (string) Str::uuid();
            }
        });

        static::deleting(function (DvrRecording $recording): void {
            // Delete the physical file from disk using the storage facade (file_path is relative).
            if ($recording->file_path) {
                $disk = $recording->dvrSetting?->storage_disk ?: config('dvr.storage_disk', 'local');

                try {
                    if (Storage::disk($disk)->exists($recording->file_path)) {
                        Storage::disk($disk)->delete($recording->file_path);
                    }
                } catch (\Throwable $e) {
                    Log::warning("DvrRecording deleting hook: could not delete file {$recording->file_path}: {$e->getMessage()}", [
                        'recording_id' => $recording->id,
                    ]);
                }
            }

            // Cascade to VOD channel and episode inside a transaction so both nulls + deletes
            // are atomic. The dvr_recording_id is nulled first so the Channel/Episode deleting
            // hooks don't attempt to re-delete this recording (re-entrance guard).
            DB::transaction(function () use ($recording): void {
                if ($vodChannel = $recording->vodChannel) {
                    $vodChannel->dvr_recording_id = null;
                    $vodChannel->save();
                    $vodChannel->delete();
                }

                if ($vodEpisode = $recording->vodEpisode) {
                    $vodEpisode->dvr_recording_id = null;
                    $vodEpisode->save();
                    $vodEpisode->delete();
                }
            });

            // Cascade to the recording rule that produced this recording.
            // - Once / Manual rules: always remove (one-shot).
            // - Series rules: remove only when this was the last sibling recording
            //   so an in-progress season keeps its rule alive.
            $rule = $recording->recordingRule;
            if ($rule) {
                $isOneShot = in_array($rule->type, [DvrRuleType::Once, DvrRuleType::Manual], true);
                $hasSiblings = DvrRecording::where('dvr_recording_rule_id', $rule->id)
                    ->where('id', '!=', $recording->id)
                    ->exists();

                if ($isOneShot || ! $hasSiblings) {
                    try {
                        $rule->delete();
                    } catch (\Throwable $e) {
                        Log::warning("DvrRecording deleting hook: could not delete rule {$rule->id}: {$e->getMessage()}", [
                            'recording_id' => $recording->id,
                        ]);
                    }
                }
            }

            // Best-effort: prune now-empty parent directories up to (but not including)
            // the library root, so the storage tree doesn't accumulate empty Year/Title dirs.
            if ($recording->file_path) {
                $disk = $recording->dvrSetting?->storage_disk ?: config('dvr.storage_disk', 'local');
                self::pruneEmptyParentDirs($disk, $recording->file_path, 'library');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlistAuth(): BelongsTo
    {
        return $this->belongsTo(PlaylistAuth::class);
    }

    public function dvrSetting(): BelongsTo
    {
        return $this->belongsTo(DvrSetting::class);
    }

    public function recordingRule(): BelongsTo
    {
        return $this->belongsTo(DvrRecordingRule::class, 'dvr_recording_rule_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** The VOD Channel created from this recording (movie integration). */
    public function vodChannel(): HasOne
    {
        return $this->hasOne(Channel::class, 'dvr_recording_id');
    }

    /** The VOD Episode created from this recording (TV series integration). */
    public function vodEpisode(): HasOne
    {
        return $this->hasOne(Episode::class, 'dvr_recording_id');
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', DvrRecordingStatus::Scheduled);
    }

    public function scopeRecording(Builder $query): Builder
    {
        return $query->where('status', DvrRecordingStatus::Recording);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', DvrRecordingStatus::Completed);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', DvrRecordingStatus::Failed);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereIn('status', [
            DvrRecordingStatus::Scheduled,
            DvrRecordingStatus::Recording,
        ])->orderBy('scheduled_start');
    }

    /**
     * Whether this recording has a completed file on disk.
     */
    public function hasFilePath(): bool
    {
        return $this->status === DvrRecordingStatus::Completed && ! empty($this->file_path);
    }

    /**
     * Get a human-readable display title (with S/E info if available).
     */
    public function getDisplayTitleAttribute(): string
    {
        $title = $this->title;

        if ($this->season !== null && $this->episode !== null) {
            $title .= sprintf(' S%02dE%02d', $this->season, $this->episode);
        }

        if (! empty($this->subtitle)) {
            $title .= ' - '.$this->subtitle;
        }

        return $title;
    }

    /**
     * Whether comskip (commercial detection) should run for this recording.
     *
     * Per-rule setting takes precedence when explicitly set;
     * otherwise falls back to the DvrSetting default.
     */
    public function shouldRunComskip(): bool
    {
        $rule = $this->recordingRule;
        if ($rule && $rule->enable_comskip !== null) {
            return (bool) $rule->enable_comskip;
        }

        return $this->dvrSetting?->enable_comskip ?? false;
    }

    /**
     * Resolve the comskip .ini file path for this recording.
     *
     * If the DvrSetting specifies a custom ini path that exists on the storage
     * disk, it is used. Otherwise the bundled default is returned.
     */
    public function resolveComskipIniPath(): string
    {
        $setting = $this->dvrSetting;
        $diskName = $setting?->storage_disk ?: config('dvr.storage_disk', 'dvr');

        if ($setting && $setting->comskip_ini_path) {
            try {
                if (Storage::disk($diskName)->exists($setting->comskip_ini_path)) {
                    return Storage::disk($diskName)->path($setting->comskip_ini_path);
                }
            } catch (\Exception) {
                // Fall through to default
            }
        }

        return config('dvr.comskip_default_ini');
    }

    /**
     * Walk up from $relativePath's directory and delete each directory that is
     * empty after the file has been removed. Stops at $stopAt (exclusive) or
     * at the disk root, whichever comes first.
     *
     * The file at $relativePath is assumed to already be deleted by the caller.
     */
    private static function pruneEmptyParentDirs(string $disk, string $relativePath, string $stopAt): void
    {
        try {
            $storage = Storage::disk($disk);
            $stopAt = trim($stopAt, '/');
            $dir = trim((string) dirname($relativePath), '/');

            // Walk up: library/2024/Show -> library/2024 -> library (stop)
            while ($dir !== '' && $dir !== '.' && $dir !== $stopAt) {
                if (! $storage->exists($dir)) {
                    break;
                }

                $files = $storage->files($dir);
                $subdirs = $storage->directories($dir);

                if (! empty($files) || ! empty($subdirs)) {
                    break;
                }

                $storage->deleteDirectory($dir);

                $parent = trim((string) dirname($dir), '/');
                if ($parent === $dir) {
                    break;
                }
                $dir = $parent;
            }
        } catch (\Throwable $e) {
            Log::warning("DvrRecording: pruneEmptyParentDirs failed for {$relativePath}: {$e->getMessage()}");
        }
    }
}
