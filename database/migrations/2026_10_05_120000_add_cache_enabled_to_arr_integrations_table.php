<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Send Cache Now and dynamic-group auto-cache requests to this Radarr or
     * Sonarr instead of the provider (see CachedContentArrService).
     */
    public function up(): void
    {
        Schema::table('arr_integrations', function (Blueprint $table) {
            $table->boolean('cache_enabled')->default(false)->after('guest_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('arr_integrations', function (Blueprint $table) {
            $table->dropColumn('cache_enabled');
        });
    }
};
