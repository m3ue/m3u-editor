<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Records a permanent Schedules Direct login rejection (account expired,
     * invalid user, application disabled, account inactive) so scheduled syncs
     * stop calling /token until the user changes the credentials or retries,
     * plus a counter for the capped exponential backoff after server errors.
     * Nullable or constant-default columns with no index, so this is a
     * metadata-only change on Postgres and does not lock the epgs table
     * against the running import job chains.
     */
    public function up(): void
    {
        Schema::table('epgs', function (Blueprint $table) {
            $table->unsignedSmallInteger('sd_auth_blocked_code')->nullable()->after('sd_login_cooldown_until');
            $table->text('sd_auth_blocked_message')->nullable()->after('sd_auth_blocked_code');
            $table->unsignedSmallInteger('sd_backoff_attempts')->default(0)->after('sd_auth_blocked_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epgs', function (Blueprint $table) {
            $table->dropColumn(['sd_auth_blocked_code', 'sd_auth_blocked_message', 'sd_backoff_attempts']);
        });
    }
};
