<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds `dropped_at` (nullable timestamp) to the cached_content_file_dynamic_groups
     * pivot. Used by DynamicGroupCacheRetentionService to implement the
     * `lifetime_plus_days` retention mode: when content falls out of a group's live
     * membership, we stamp `dropped_at` on the pivot row and only actually delete
     * the pivot row once `dropped_at + cache_retention_extra_days` has elapsed.
     *
     * Nullable column with no default — adding it doesn't lock the table on Postgres
     * (no rewrite, just metadata for new rows; existing rows get NULL).
     */
    public function up(): void
    {
        Schema::table('cached_content_file_dynamic_groups', function (Blueprint $table) {
            $table->timestamp('dropped_at')->nullable()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('cached_content_file_dynamic_groups', function (Blueprint $table) {
            $table->dropColumn('dropped_at');
        });
    }
};
