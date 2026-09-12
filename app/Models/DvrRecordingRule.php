<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\DvrMatchMode;
use App\Enums\DvrRecordingStatus;
use App\Enums\DvrRuleType;
use App\Enums\DvrSeriesMode;
use App\Services\DvrSchedulerService;
use App\Support\SeriesKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DvrRecordingRule extends Model
{
    use HasFactory;

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
            'enable_comskip' => 'boolean',
            'sports_dedup_days' => 'integer',
            'manual_start' => UtcDateTime::class,
            'manual_end' => UtcDateTime::class,
        ];
    }

    /**
     * Dedup window for this rule's sports airings (no season/episode data).
     * Rule override wins; falls back to the DVR setting's default (2 days).
     * 0 = record every same-title airing.
     */
    public function sportsDedupDays(): int
    {
        if ($this->sports_dedup_days !== null) {
            return max(0, (int) $this->sports_dedup_days);
        }

        return $this->dvrSetting?->sportsDedupDays() ?? 2;
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

        static::creating(function (self $rule): void {
            if (! $rule->user_id) {
                $rule->user_id = auth()->id();
            }
        });

        static::created(function (self $rule): void {
            // Immediate scheduling matters for every rule type now that the per-minute
            // tick no longer matches EPG data (see DvrSchedulerService::tick()) — Once
            // and Manual rules would otherwise sit unscheduled until the next DvrDeepScan.
            if ($rule->enabled) {
                app(DvrSchedulerService::class)->scheduleRuleImmediately($rule);
            }
        });

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

            // Detect enabled transition false → true and trigger immediate scheduling
            // for every rule type — see the comment in the `created` hook above.
            if (
                $rule->enabled
                && $rule->getOriginal('enabled') === false
            ) {
                app(DvrSchedulerService::class)->scheduleRuleImmediately($rule);
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

    public function sourceChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'source_channel_id');
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
        return $this->getEpisodeRecordingStatus($seriesKey, $season, $episode) !== null;
    }

    /**
     * Get the status of an existing recording for the given series_key + season + episode.
     * Returns the status string ('scheduled', 'completed', 'purged', etc.) or null if no recording exists.
     *
     * Purged is deliberately included: retention deletes the recording file but
     * keeps the database row as the record that the episode has already been
     * recorded, so a rule set to avoid repeat episodes must not schedule the
     * same season + episode again after cleanup.
     */
    public function getEpisodeRecordingStatus(string $seriesKey, ?int $season, ?int $episode): ?string
    {
        if ($season === null || $episode === null) {
            $recording = DvrRecording::where('series_key', $seriesKey)
                ->whereIn('status', [
                    DvrRecordingStatus::Scheduled,
                    DvrRecordingStatus::Recording,
                    DvrRecordingStatus::PostProcessing,
                    DvrRecordingStatus::Completed,
                    DvrRecordingStatus::Purged,
                ])
                ->first();

            return $recording?->status?->value;
        }

        $recording = DvrRecording::where('series_key', $seriesKey)
            ->where('season', $season)
            ->where('episode', $episode)
            ->whereIn('status', [
                DvrRecordingStatus::Scheduled,
                DvrRecordingStatus::Recording,
                DvrRecordingStatus::PostProcessing,
                DvrRecordingStatus::Completed,
                DvrRecordingStatus::Purged,
            ])
            ->first();

        return $recording?->status?->value;
    }

    /**
     * Sports identity (no season/episode): same series_key within a dedup
     * WINDOW (see DvrSetting::sportsDedupDays) of an existing recording = a
     * replay of the same game (skipped — even after retention purged the file,
     * the row is kept). Beyond the window it is a NEW event (a re-match later
     * in the season) and must record. A same-day replay is always a duplicate.
     */
    public function getEpisodeRecordingStatusOnDate(string $seriesKey, \DateTimeInterface $date, int $windowDays): ?string
    {
        $from = (new CarbonImmutable($date))->subDays($windowDays);

        $recording = DvrRecording::where('series_key', $seriesKey)
            ->whereBetween('scheduled_start', [$from, $date])
            ->whereIn('status', [
                DvrRecordingStatus::Scheduled,
                DvrRecordingStatus::Recording,
                DvrRecordingStatus::PostProcessing,
                DvrRecordingStatus::Completed,
                DvrRecordingStatus::Purged,
            ])
            ->orderByDesc('scheduled_start')
            ->first();

        return $recording?->status?->value;
    }

    /**
     * Sports identity duplicate check — see getEpisodeRecordingStatusOnDate().
     */
    public function alreadyHaveEpisodeOnDate(string $seriesKey, \DateTimeInterface $date, int $windowDays): bool
    {
        return $this->getEpisodeRecordingStatusOnDate($seriesKey, $date, $windowDays) !== null;
    }

    /**
     * In-window duplicate check against dry-run scheduled keys (sports keys
     * are seriesKey|DATE): true when a same-title airing within [windowStart,
     * date] was already scheduled in this dry run.
     *
     * @param  list<string>  $scheduledKeys
     */
    public function sportsAlreadyScheduled(string $seriesKey, \DateTimeInterface $date, int $windowDays, array $scheduledKeys): bool
    {
        if ($windowDays === 0) {
            return in_array($seriesKey.'|'.$date->format('Y-m-d'), $scheduledKeys, true);
        }

        $windowStart = $date->format('Y-m-d');
        $prefix = $seriesKey.'|';
        foreach ($scheduledKeys as $key) {
            if (! str_starts_with($key, $prefix)) {
                continue;
            }

            $scheduledDate = substr($key, strlen($prefix));
            $scheduledTimestamp = strtotime($scheduledDate);
            $dateTimestamp = strtotime($date->format('Y-m-d'));

            if ($scheduledTimestamp !== false
                && $dateTimestamp !== false
                && $scheduledTimestamp >= $dateTimestamp - $windowDays * 86400
                && $scheduledTimestamp <= $dateTimestamp) {
                return true;
            }
        }

        return false;
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
