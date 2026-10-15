<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Widen the type enum so series entries can be logged
        if (DB::getDriverName() === 'pgsql') {
            // Drop the named CHECK constraint - column is already varchar, nothing else needed.
            DB::statement('ALTER TABLE playlist_sync_status_logs DROP CONSTRAINT IF EXISTS playlist_sync_status_logs_type_check');
        } else {
            Schema::table('playlist_sync_status_logs', function (Blueprint $table) {
                $table->string('type')->default('unknown')->change();
            });
        }

        Schema::table('playlist_sync_status_logs', function (Blueprint $table) {
            $table->string('content_type')->nullable()->after('type');
        });

        // Backfill existing entries from the logged model snapshot
        DB::table('playlist_sync_status_logs')
            ->where('type', 'channel')
            ->where('meta->is_vod', true)
            ->update(['content_type' => 'vod']);
        DB::table('playlist_sync_status_logs')
            ->where('type', 'group')
            ->where('meta->type', 'vod')
            ->update(['content_type' => 'vod']);
        DB::table('playlist_sync_status_logs')
            ->whereIn('type', ['channel', 'group'])
            ->whereNull('content_type')
            ->update(['content_type' => 'live']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('playlist_sync_status_logs')
            ->whereNotIn('type', ['group', 'channel', 'unknown'])
            ->delete();

        Schema::table('playlist_sync_status_logs', function (Blueprint $table) {
            $table->dropColumn('content_type');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE playlist_sync_status_logs ADD CONSTRAINT playlist_sync_status_logs_type_check CHECK (type IN ('group', 'channel', 'unknown'))");

            return;
        }

        Schema::table('playlist_sync_status_logs', function (Blueprint $table) {
            $table->enum('type', ['group', 'channel', 'unknown'])->default('unknown')->change();
        });
    }
};
