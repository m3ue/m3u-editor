<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Records why the Schedules Direct login cooldown in sd_login_cooldown_until
     * is active (login limit, IP limit, account lockout or server-error backoff),
     * so attempts suppressed during it report the right reason. Nullable with no
     * default and no index, so this is a metadata-only change on Postgres and
     * does not lock the epgs table against the running import job chains.
     */
    public function up(): void
    {
        Schema::table('epgs', function (Blueprint $table) {
            $table->string('sd_cooldown_reason', 32)->nullable()->after('sd_login_cooldown_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epgs', function (Blueprint $table) {
            $table->dropColumn('sd_cooldown_reason');
        });
    }
};
