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
        Schema::table('playlist_aliases', function (Blueprint $table) {
            // 'static' = use stored entries as-is, 'inherit' = follow the source playlist URL/failover,
            // 'independent' = per-entry fallback_urls with promote-on-failure
            $table->string('dns_failover_mode')->default('static')->after('xtream_config');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->dropColumn('dns_failover_mode');
        });
    }
};
