<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds user_cancelled and attempt_count to dvr_recordings.
     *
     * user_cancelled: true when the user explicitly cancelled the recording.
     *   Unlike system Failed recordings, user-cancelled ones should NOT be retried
     *   within the same airing window.
     *
     * attempt_count: how many times StartDvrRecording has been dispatched for this
     *   recording row. Incremented on each start. Used to bound retries of system
     *   failures to max_attempts_per_airing (config/dvr.php).
     *
     * Backfill:
     *   - user_cancelled: true for existing Cancelled rows, false for all others
     *   - attempt_count: 1 for all rows (no prior retry tracking exists)
     */
    public function up(): void
    {
        Schema::table('dvr_recordings', function (Blueprint $table): void {
            $table->boolean('user_cancelled')->default(false)->after('status');
            $table->unsignedSmallInteger('attempt_count')->default(1)->after('user_cancelled');
        });

        DB::table('dvr_recordings')
            ->where('status', 'cancelled')
            ->update(['user_cancelled' => true]);
    }

    public function down(): void
    {
        Schema::table('dvr_recordings', function (Blueprint $table): void {
            $table->dropColumn(['user_cancelled', 'attempt_count']);
        });
    }
};
