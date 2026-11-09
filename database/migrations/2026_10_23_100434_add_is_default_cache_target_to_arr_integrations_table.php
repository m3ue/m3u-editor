<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * With more than one cache-enabled integration of the same type, this picks
     * which one CachedContentArrService::integration() uses for automatic/background
     * caching (Cache Now, dynamic group caching), instead of falling back to
     * whichever instance happens to have the lowest id (issue #1669).
     */
    public function up(): void
    {
        Schema::table('arr_integrations', function (Blueprint $table) {
            $table->boolean('is_default_cache_target')->default(false)->after('cache_failback');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('arr_integrations', function (Blueprint $table) {
            $table->dropColumn('is_default_cache_target');
        });
    }
};
