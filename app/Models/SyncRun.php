<?php

namespace App\Models;

use App\Enums\SyncRunPhase;
use App\Enums\SyncRunStatus;
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
        return ($this->phase_statuses ?? [])[$phase->value] ?? 'pending';
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
        $statuses[$phase->value] = $status;
        $this->update([
            'phase_statuses' => $statuses,
            'current_phase' => $phase->value,
        ]);
    }

    /** @return array<int, array{phase: string, label: string, status: string}> */
    public function getPhaseTimelineAttribute(): array
    {
        return array_map(
            fn (SyncRunPhase $phase) => [
                'phase' => $phase->value,
                'label' => $phase->getLabel(),
                'status' => $this->getStatusForPhase($phase),
            ],
            $this->getOrderedPhases()
        );
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
