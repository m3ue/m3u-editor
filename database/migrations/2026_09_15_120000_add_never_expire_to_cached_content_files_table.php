<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persist a sticky "never expire" flag on each cached_content_files row.
     *
     * PR #1500 Bug 3: SyncDynamicGroups hard-deletes stale DynamicGroup rows
     * (for rules that were renamed or disabled), and the
     * cached_content_file_dynamic_groups pivot has cascadeOnDelete on both FKs,
     * so all pivot rows for that group disappear in the same transaction.
     * DynamicGroupCacheRetentionService then sees the file as orphaned and
     * hard-deletes it — even when the rule that just got removed was set to
     * `cache_retention_mode=never_expire`.
     *
     * A boolean column on the cached file (rather than a separate
     * "expires_at" table or a per-rule pivot) is the minimal schema delta:
     * the file is the natural anchor for "this content was once pinned forever
     * by a rule that no longer exists." Subsequent rule edits can re-arm it
     * (syncDynamicGroups pins it on the way out; a future sync that re-adds
     * the same rule can clear it), and retention has one short-circuit check
     * instead of needing to walk the rule-config history.
     *
     * Nullable boolean default false so the migration is safe on existing
     * rows (they pre-date the policy and aren't eligible for the never_expire
     * pin retroactively). Postgres handles this as a metadata-only change
     * for nullable columns with no rewrite.
     */
    public function up(): void
    {
        Schema::table('cached_content_files', function (Blueprint $table): void {
            $table->boolean('never_expire')->default(false)->after('failure_count');
        });
    }

    public function down(): void
    {
        Schema::table('cached_content_files', function (Blueprint $table): void {
            $table->dropColumn('never_expire');
        });
    }
};
