<?php

namespace App\Models;

use App\Enums\DvrRecordingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DvrRecording extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'user_id',
        'dvr_setting_id',
        'dvr_recording_rule_id',
        'channel_id',
        'status',
        'title',
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
        'programme_start',
        'programme_end',
        'epg_programme_data',
        'pid',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DvrRecordingStatus::class,
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
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
        return $query->where('status', DvrRecordingStatus::Scheduled->value);
    }

    public function scopeRecording(Builder $query): Builder
    {
        return $query->where('status', DvrRecordingStatus::Recording->value);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', DvrRecordingStatus::Completed->value);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', DvrRecordingStatus::Failed->value);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereIn('status', [
            DvrRecordingStatus::Scheduled->value,
            DvrRecordingStatus::Recording->value,
        ])->orderBy('scheduled_start');
    }

    /**
     * Get the temporary HLS directory path (relative to the dvr disk).
     */
    public function getTempPathAttribute(): string
    {
        return 'live/'.$this->uuid;
    }

    /**
     * Get the temporary M3U8 manifest path (relative to the dvr disk).
     */
    public function getTempManifestPathAttribute(): string
    {
        return 'live/'.$this->uuid.'/stream.m3u8';
    }

    /**
     * Whether this recording has a completed file on disk.
     */
    public function hasFile(): bool
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
}
