<?php

namespace App\Models;

use App\Enums\SyncPhaseStatus;
use App\Enums\SyncRunStatus;
use Database\Factories\SyncRunFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Ledger row for a single playlist sync attempt.
 *
 * SyncRun is the new source of truth for sync state. The legacy `processing`
 * JSON column on `playlists` (and the `vod_progress` / `series_progress` /
 * `processing_phase` columns) will be migrated onto SyncRun in a later step.
 *
 * Phases stored in the `phases` JSONB column are keyed by a phase slug and
 * each entry is a record of:
 *   {
 *     "status":      "pending|running|completed|failed|skipped",
 *     "started_at":  ISO-8601 string|null,
 *     "finished_at": ISO-8601 string|null,
 *     "error":       string|null,
 *     "meta":        array|null,
 *   }
 *
 * @property int $id
 * @property string $uuid
 * @property int $playlist_id
 * @property int $user_id
 * @property string $kind
 * @property string $trigger
 * @property SyncRunStatus $status
 * @property array<string, array<string, mixed>> $phases
 * @property array<int|string, mixed>|null $errors
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Playlist $playlist
 * @property-read User $user
 */
class SyncRun extends Model
{
    /** @use HasFactory<SyncRunFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'playlist_id',
        'user_id',
        'kind',
        'trigger',
        'status',
        'phases',
        'errors',
        'meta',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SyncRunStatus::class,
            'phases' => 'array',
            'errors' => 'array',
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            if (empty($run->uuid)) {
                $run->uuid = (string) Str::uuid();
            }

            if (empty($run->phases)) {
                $run->phases = [];
            }
        });
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // -------------------------------------------------------------------------
    // Static constructors
    // -------------------------------------------------------------------------

    /**
     * Open a new run for a playlist in the Pending state.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function openFor(
        Playlist $playlist,
        string $kind = 'full',
        string $trigger = 'manual',
        array $meta = [],
    ): self {
        return self::create([
            'playlist_id' => $playlist->getKey(),
            'user_id' => $playlist->user_id,
            'kind' => $kind,
            'trigger' => $trigger,
            'status' => SyncRunStatus::Pending,
            'meta' => $meta ?: null,
        ]);
    }

    // -------------------------------------------------------------------------
    // Lifecycle helpers
    // -------------------------------------------------------------------------

    /**
     * Transition the run to Running and stamp `started_at` if not already set.
     */
    public function markStarted(): self
    {
        $this->forceFill([
            'status' => SyncRunStatus::Running,
            'started_at' => $this->started_at ?? now(),
        ])->save();

        return $this;
    }

    public function markCompleted(): self
    {
        $this->forceFill([
            'status' => SyncRunStatus::Completed,
            'finished_at' => now(),
        ])->save();

        return $this;
    }

    /**
     * Transition the run to Failed and append an error entry.
     */
    public function markFailed(string|\Throwable $error): self
    {
        $this->recordError($error);

        $this->forceFill([
            'status' => SyncRunStatus::Failed,
            'finished_at' => now(),
        ])->save();

        return $this;
    }

    public function markCancelled(?string $reason = null): self
    {
        if ($reason !== null) {
            $this->recordError($reason);
        }

        $this->forceFill([
            'status' => SyncRunStatus::Cancelled,
            'finished_at' => now(),
        ])->save();

        return $this;
    }

    /**
     * Append an arbitrary error entry to the run-level error log.
     */
    public function recordError(string|\Throwable $error, ?string $phase = null): self
    {
        $entry = [
            'message' => $error instanceof \Throwable ? $error->getMessage() : $error,
            'phase' => $phase,
            'at' => now()->toIso8601String(),
        ];

        if ($error instanceof \Throwable) {
            $entry['exception'] = get_class($error);
        }

        $existing = $this->errors ?? [];
        $existing[] = $entry;
        $this->errors = $existing;
        $this->save();

        return $this;
    }

    // -------------------------------------------------------------------------
    // Phase helpers
    // -------------------------------------------------------------------------

    /**
     * Mark a phase as Running. Idempotent — repeated calls keep the original
     * `started_at` so a retry doesn't reset elapsed time tracking.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function markPhaseStarted(string $phase, ?array $meta = null): self
    {
        return $this->writePhase($phase, [
            'status' => SyncPhaseStatus::Running->value,
            'started_at' => $this->phases[$phase]['started_at'] ?? now()->toIso8601String(),
            'finished_at' => null,
            'meta' => $meta,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function markPhaseCompleted(string $phase, ?array $meta = null): self
    {
        return $this->writePhase($phase, [
            'status' => SyncPhaseStatus::Completed->value,
            'finished_at' => now()->toIso8601String(),
            'meta' => $meta ?? ($this->phases[$phase]['meta'] ?? null),
        ]);
    }

    public function markPhaseSkipped(string $phase, ?string $reason = null): self
    {
        return $this->writePhase($phase, [
            'status' => SyncPhaseStatus::Skipped->value,
            'finished_at' => now()->toIso8601String(),
            'meta' => $reason !== null ? ['reason' => $reason] : ($this->phases[$phase]['meta'] ?? null),
        ]);
    }

    public function markPhaseFailed(string $phase, string|\Throwable $error): self
    {
        $message = $error instanceof \Throwable ? $error->getMessage() : $error;

        $this->writePhase($phase, [
            'status' => SyncPhaseStatus::Failed->value,
            'finished_at' => now()->toIso8601String(),
            'error' => $message,
        ]);

        // Mirror into the run-level error log for easy aggregation.
        return $this->recordError($error, $phase);
    }

    public function phaseStatus(string $phase): SyncPhaseStatus
    {
        $value = $this->phases[$phase]['status'] ?? null;

        return $value !== null
            ? SyncPhaseStatus::from($value)
            : SyncPhaseStatus::Pending;
    }

    public function isRunning(): bool
    {
        return $this->status === SyncRunStatus::Running;
    }

    public function isFinished(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Convenience accessor returning the slug of whichever phase is currently
     * Running, or null if the run is between phases.
     */
    protected function currentPhase(): Attribute
    {
        return Attribute::get(function (): ?string {
            foreach ($this->phases ?? [] as $slug => $data) {
                if (($data['status'] ?? null) === SyncPhaseStatus::Running->value) {
                    return $slug;
                }
            }

            return null;
        });
    }

    /**
     * Merge updates into a phase entry, preserving previously written keys.
     *
     * @param  array<string, mixed>  $updates
     */
    protected function writePhase(string $phase, array $updates): self
    {
        $phases = $this->phases ?? [];
        $existing = $phases[$phase] ?? [];

        $phases[$phase] = array_replace($existing, array_filter(
            $updates,
            static fn ($value) => $value !== null,
        ));

        $this->phases = $phases;
        $this->save();

        return $this;
    }
}
