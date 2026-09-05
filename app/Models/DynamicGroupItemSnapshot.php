<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Per-run snapshot of the items a DynamicGroup contained at the moment the
 * `dynamic_groups` phase completed. Captured by `SyncDynamicGroups` after its
 * destructive full-sync so the prior membership is preserved for the "what
 * changed in run N" diff the View page renders.
 *
 * Pruned at 30 days (matching `sync_runs` and `playlist_sync_statuses`) so
 * the on-disk footprint stays bounded at typical workloads. Cron-driven
 * `app:refresh-dynamic-groups` runs skip capture (their SyncRun is null) so
 * the diff view only sees pipeline-attributable runs.
 */
class DynamicGroupItemSnapshot extends Model
{
    use HasFactory;
    use MassPrunable;

    /**
     * Captured-at is the only timestamp we care about for pruning and
     * diffing; rows are append-only so created_at/updated_at would just be
     * noise (and would force an extra index on every sync).
     */
    public $timestamps = false;

    protected $fillable = [
        'dynamic_group_id',
        'sync_run_id',
        'item_type',
        'item_id',
        'captured_at',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
    ];

    public function prunable(): Builder
    {
        return static::query()->where('captured_at', '<', now()->subDays(30));
    }

    public function dynamicGroup(): BelongsTo
    {
        return $this->belongsTo(DynamicGroup::class);
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class);
    }

    /**
     * Compute the membership change for a single group between two sync runs.
     *
     * `$previousRunId` may be null — in that case the "removed" set is empty
     * (we can't diff against nothing) and all items in the current snapshot
     * are returned as added. This matches the first-sync case.
     *
     * The "previous" snapshot is the most recent run strictly before
     * `$currentRunId` for the same group, NOT necessarily `$previousRunId`
     * itself — that lets callers pass only `$currentRunId` and still get a
     * meaningful answer when no explicit previous is known. Snapshots older
     * than 30 days are pruned, so the diff is always against a recent-ish
     * baseline.
     *
     * @return array{added: Collection<int, int>, removed: Collection<int, int>, has_previous: bool}
     */
    public static function diffForRun(int $dynamicGroupId, ?int $currentRunId): array
    {
        if ($currentRunId === null) {
            return ['added' => collect(), 'removed' => collect(), 'has_previous' => false];
        }

        $currentIds = static::query()
            ->where('dynamic_group_id', $dynamicGroupId)
            ->where('sync_run_id', $currentRunId)
            ->pluck('item_id');

        $previousRunId = static::query()
            ->where('dynamic_group_id', $dynamicGroupId)
            ->where('sync_run_id', '<', $currentRunId)
            ->whereNotNull('sync_run_id')
            ->max('sync_run_id');

        if ($previousRunId === null) {
            return ['added' => $currentIds, 'removed' => collect(), 'has_previous' => false];
        }

        $previousIds = static::query()
            ->where('dynamic_group_id', $dynamicGroupId)
            ->where('sync_run_id', $previousRunId)
            ->pluck('item_id');

        return [
            'added' => $currentIds->diff($previousIds)->values(),
            'removed' => $previousIds->diff($currentIds)->values(),
            'has_previous' => true,
        ];
    }

    /**
     * Item-type map for a group's snapshot at a given run. Used by the View
     * page to resolve added/removed item IDs to display titles (channels vs
     * series live in separate tables and the snapshot only stores the morph
     * class string).
     *
     * @return Collection<int, string> keyed by item_id, values are morph class names
     */
    public static function itemsForRun(int $dynamicGroupId, int $runId): Collection
    {
        return static::query()
            ->where('dynamic_group_id', $dynamicGroupId)
            ->where('sync_run_id', $runId)
            ->get(['item_id', 'item_type'])
            ->keyBy('item_id')
            ->map(fn ($row): string => $row->item_type);
    }
}
