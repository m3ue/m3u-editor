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
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->jsonb('processing_config')->nullable()->after('proxy_options');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->dropColumn('processing_config');
        });
    }
};
