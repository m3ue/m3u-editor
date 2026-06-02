<?php

namespace App\Models;

use App\Enums\SyncRunPhase;
use App\Enums\SyncRunStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncRun extends Model
{
    use HasFactory;
    use MassPrunable;

    protected $casts = [
        'phases' => 'array',
        'phase_statuses' => 'array',
        'context' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function prunable(): Builder
    {
        return static::query()
            ->whereNotIn('status', [SyncRunStatus::Running->value])
            ->where('created_at', '<', now()->subDays(30));
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return SyncRunPhase[] */
    public function getOrderedPhases(): array
    {
        return array_map(
            fn (string $value) => SyncRunPhase::from($value),
            $this->phases ?? []
        );
    }

    public function getStatusForPhase(SyncRunPhase $phase): string
    {
        $value = ($this->phase_statuses ?? [])[$phase->value] ?? null;

        if ($value === null) {
            return 'pending';
        }

        // Support both legacy string format ('completed') and enriched array format
        // (['status' => 'completed', 'at' => '2026-05-18T10:00:00Z']).
        return is_array($value) ? ($value['status'] ?? 'pending') : $value;
    }

    public function getPhaseCompletedAt(SyncRunPhase $phase): ?Carbon
    {
        $value = ($this->phase_statuses ?? [])[$phase->value] ?? null;

        if (! is_array($value) || empty($value['at'])) {
            return null;
        }

        return Carbon::parse($value['at']);
    }

    public function isPhaseComplete(SyncRunPhase $phase): bool
    {
        return in_array($this->getStatusForPhase($phase), ['completed', 'skipped']);
    }

    public function getNextPendingPhase(): ?SyncRunPhase
    {
        foreach ($this->getOrderedPhases() as $phase) {
            if (! $this->isPhaseComplete($phase)) {
                return $phase;
            }
        }

        return null;
    }

    public function markPhase(SyncRunPhase $phase, string $status): void
    {
        $statuses = $this->phase_statuses ?? [];
        $statuses[$phase->value] = ['status' => $status, 'at' => now()->toIso8601String()];
        $this->update([
            'phase_statuses' => $statuses,
            'current_phase' => $phase->value,
        ]);
    }

    /**
     * @return array<int, array{phase: string, label: string, status: string, duration: string|null}>
     */
    public function getPhaseTimelineAttribute(): array
    {
        $isRunning = $this->status === SyncRunStatus::Running->value;
        $prevTime = $this->started_at;
        $result = [];

        foreach ($this->getOrderedPhases() as $phase) {
            $rawStatus = $this->getStatusForPhase($phase);
            $completedAt = $this->getPhaseCompletedAt($phase);

            // Derive duration from successive completion timestamps.
            $duration = null;
            if ($completedAt && $prevTime) {
                $seconds = (int) $prevTime->diffInSeconds($completedAt);
                $duration = $seconds >= 60
                    ? intdiv($seconds, 60).'m '.($seconds % 60).'s'
                    : $seconds.'s';
            }

            if ($completedAt) {
                $prevTime = $completedAt;
            }

            $result[] = [
                'phase' => $phase->value,
                'label' => $phase->getLabel(),
                'status' => ($isRunning && $this->current_phase === $phase->value)
                    ? 'running'
                    : $rawStatus,
                'duration' => $duration,
            ];
        }

        return $result;
    }

    public function getDurationAttribute(): ?float
    {
        if (! $this->started_at) {
            return null;
        }

        return $this->started_at->diffInSeconds($this->finished_at ?? now());
    }

    public function isStale(int $minutes = 60): bool
    {
        if ($this->status !== SyncRunStatus::Running->value || ! $this->updated_at) {
            return false;
        }

        return $this->updated_at->lt(now()->subMinutes($minutes));
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where('user_id', $user->id);
    }
}
