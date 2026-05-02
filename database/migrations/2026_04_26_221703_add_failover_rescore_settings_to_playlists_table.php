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
            $table->string('auto_rescore_failovers_interval')->nullable()->after('probe_timeout');
            $table->timestamp('last_failover_rescore_at')->nullable()->after('auto_rescore_failovers_interval');
            $table->unsignedSmallInteger('failover_rescore_staleness_days')->default(7)->after('last_failover_rescore_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn([
                'auto_rescore_failovers_interval',
                'last_failover_rescore_at',
                'failover_rescore_staleness_days',
            ]);
        });
    }
};
