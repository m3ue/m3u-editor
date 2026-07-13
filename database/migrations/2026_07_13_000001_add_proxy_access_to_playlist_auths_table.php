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
            $table->boolean('proxy_enabled')->default(false)->after('stop_oldest_on_limit');
            // 'all' = every owner profile, 'selected' = only proxy_stream_profile_ids, 'none' = direct proxy only (no transcoding)
            $table->string('proxy_profile_access')->default('all')->after('proxy_enabled');
            $table->json('proxy_stream_profile_ids')->nullable()->after('proxy_profile_access');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlist_auths', function (Blueprint $table) {
            $table->dropColumn(['proxy_enabled', 'proxy_profile_access', 'proxy_stream_profile_ids']);
        });
    }
};
