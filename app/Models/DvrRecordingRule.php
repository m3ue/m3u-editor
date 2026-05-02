<?php

namespace App\Models;

use App\Enums\DvrMatchMode;
use App\Enums\DvrRecordingStatus;
use App\Enums\DvrRuleType;
use App\Enums\DvrSeriesMode;
use App\Support\SeriesKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DvrRecordingRule extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'playlist_auth_id',
        'dvr_setting_id',
        'type',
        'programme_id',
        'series_title',
        'series_key',
        'normalized_title',
        'channel_id',
        'epg_channel_id',
        'new_only',
        'series_mode',
        'match_mode',
        'tmdb_id',
        'priority',
        'start_early_seconds',
        'end_late_seconds',
        'keep_last',
        'enabled',
        'manual_start',
        'manual_end',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DvrRuleType::class,
            'new_only' => 'boolean',
            'series_mode' => DvrSeriesMode::class,
            'match_mode' => DvrMatchMode::class,
            'priority' => 'integer',
            'start_early_seconds' => 'integer',
            'end_late_seconds' => 'integer',
            'keep_last' => 'integer',
            'enabled' => 'boolean',
            'manual_start' => 'datetime',
            'manual_end' => 'datetime',
        ];
    }

    /**
     * Auto-derive series_key + normalized_title from series_title for Series rules
     * so application code doesn't have to compute it on every write. Once/Manual
     * rules don't carry a stable title at the rule level — those are derived per
     * recording at schedule time inside DvrSchedulerService.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $rule): void {
            if (
                $rule->type === DvrRuleType::Series
                && ! empty($rule->series_title)
                && $rule->dvr_setting_id
            ) {
                $rule->normalized_title = SeriesKey::normalize($rule->series_title) ?: null;
                $rule->series_key = SeriesKey::for((int) $rule->dvr_setting_id, $rule->series_title);
            }

            // Migrate new_only boolean to series_mode enum at write time.
            // Reads raw attributes directly to avoid circular dependency with the new_only accessor.
            $rawNewOnly = $rule->attributes['new_only'] ?? false;
            if ($rawNewOnly && ($rule->series_mode !== DvrSeriesMode::NewFlag)) {
                $rule->series_mode = DvrSeriesMode::NewFlag;
            } elseif (! $rawNewOnly && $rule->series_mode === DvrSeriesMode::NewFlag) {
                $rule->series_mode = DvrSeriesMode::All;
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

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function epgChannel(): BelongsTo
    {
        return $this->belongsTo(EpgChannel::class);
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(DvrRecording::class);
    }

    /**
     * Check whether a specific (season, episode) pair has already been recorded
     * under the same series_key, regardless of programme start time.
     *
     * Active statuses (Scheduled/Recording/PostProcessing) block a re-record because
     * the episode is already in-progress. Completed also blocks to prevent duplicate
     * files. Cancelled and Failed do not block — a user cancelled recording should
     * be allowed to re-record.
     *
     * Returns true when an active or completed recording exists for the same
     * series_key + season + episode.
     */
    public function alreadyHaveEpisode(string $seriesKey, ?int $season, ?int $episode): bool
    {
        if ($season === null || $episode === null) {
            return false;
        }

        return DvrRecording::where('series_key', $seriesKey)
            ->where('season', $season)
            ->where('episode', $episode)
            ->whereIn('status', [
                DvrRecordingStatus::Scheduled,
                DvrRecordingStatus::Recording,
                DvrRecordingStatus::PostProcessing,
                DvrRecordingStatus::Completed,
            ])
            ->exists();
    }

    /**
     * Compatibility accessor: returns true when series_mode is NewFlag.
     * Used by Filament table columns and any legacy code that reads new_only directly.
     * The authoritative field is now series_mode.
     */
    public function getNewOnlyAttribute(): bool
    {
        return $this->series_mode === DvrSeriesMode::NewFlag;
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(EpgProgramme::class, 'programme_id');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function scopeSeries(Builder $query): Builder
    {
        return $query->where('type', DvrRuleType::Series);
    }

    public function scopeOnce(Builder $query): Builder
    {
        return $query->where('type', DvrRuleType::Once);
    }

    public function scopeManual(Builder $query): Builder
    {
        return $query->where('type', DvrRuleType::Manual);
    }
}
