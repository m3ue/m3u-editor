<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-(dynamic_group, sync_run) snapshot of the membership rows that were
     * active at the moment the dynamic_groups phase completed.
     *
     * Read at "what changed for group X between run N and run N+1" time by
     * left-joining snapshot rows across two runs. One row per item per group
     * per run — the table is intentionally narrow so the 30-day pruning
     * (matched to `sync_runs` and `playlist_sync_statuses`) keeps the
     * footprint bounded at ~9k rows / playlist / month on typical workloads
     * (5 groups × 30 items × 2 syncs/day × 30 days).
     *
     * No data is captured for runs where `sync_run_id` is null (the daily
     * `app:refresh-dynamic-groups` cron path) — those rows are still useful
     * for membership, just not for diff display.
     */
    public function up(): void
    {
        Schema::create('dynamic_group_item_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dynamic_group_id')
                ->constrained('dynamic_groups')
                ->cascadeOnDelete();
            $t->foreignId('sync_run_id')
                ->nullable()
                ->constrained('sync_runs')
                ->nullOnDelete();
            $t->string('item_type', 255);
            $t->unsignedBigInteger('item_id');
            $t->timestamp('captured_at')->useCurrent();

            // Hot read path: "give me the membership of group X at run N".
            // Backs the diff query on the View page.
            $t->index(['dynamic_group_id', 'sync_run_id'], 'dgi_snap_group_run_idx');

            // Inverse: "what groups did run N touch?" — unused today but cheap
            // and matches the FK on (sync_run_id).
            $t->index(['sync_run_id'], 'dgi_snap_run_idx');

            // Per-group pruning walks by captured_at; also supports
            // "when was the last snapshot for this group" lookups.
            $t->index(['dynamic_group_id', 'captured_at'], 'dgi_snap_group_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_group_item_snapshots');
    }
};
