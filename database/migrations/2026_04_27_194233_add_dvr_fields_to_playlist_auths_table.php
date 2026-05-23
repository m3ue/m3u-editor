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
        Schema::table('playlist_auths', function (Blueprint $table) {
            $table->boolean('dvr_enabled')->default(false)->after('expires_at');
            $table->unsignedSmallInteger('dvr_max_concurrent_recordings')->nullable()->after('dvr_enabled');
            $table->unsignedInteger('dvr_storage_quota_gb')->nullable()->after('dvr_max_concurrent_recordings');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlist_auths', function (Blueprint $table) {
            $table->dropColumn(['dvr_enabled', 'dvr_max_concurrent_recordings', 'dvr_storage_quota_gb']);
        });
    }
};
