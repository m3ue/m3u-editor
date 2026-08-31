<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tracks the end of a bounded cooldown entered after Schedules Direct returns
     * TOO_MANY_LOGINS (code 4009). While this is in the future we must not issue
     * any further /token requests for the account. Nullable with no default and
     * no index, so this is a metadata-only change on Postgres and does not lock
     * the epgs table against the running import job chains.
     */
    public function up(): void
    {
        Schema::table('epgs', function (Blueprint $table) {
            $table->timestamp('sd_login_cooldown_until')->nullable()->after('sd_token_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epgs', function (Blueprint $table) {
            $table->dropColumn('sd_login_cooldown_until');
        });
    }
};
