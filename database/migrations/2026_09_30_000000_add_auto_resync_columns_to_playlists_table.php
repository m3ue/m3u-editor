<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->boolean('auto_resync_on_failure')->default(true)->after('auto_sync');
            $table->unsignedSmallInteger('auto_resync_retries')->default(3)->after('auto_resync_on_failure');
            $table->unsignedSmallInteger('resync_attempt')->default(0)->after('auto_resync_retries');
        });

        // Drop the short-lived invalidation backoff columns (dev builds only)
        foreach (['sync_retry_after', 'sync_retry_count'] as $column) {
            if (Schema::hasColumn('playlists', $column)) {
                Schema::table('playlists', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn(['auto_resync_on_failure', 'auto_resync_retries', 'resync_attempt']);
        });
    }
};
