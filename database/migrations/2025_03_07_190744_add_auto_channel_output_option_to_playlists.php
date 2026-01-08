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
            $table->boolean('auto_channel_increment')->after('import_prefs')->default(false);
            $table->unsignedInteger('channel_start')->after('auto_channel_increment')->default(1);
        });

        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->boolean('auto_channel_increment')->after('uuid')->default(false);
            $table->unsignedInteger('channel_start')->after('auto_channel_increment')->default(1);
        });

        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->boolean('auto_channel_increment')->after('uuid')->default(false);
            $table->unsignedInteger('channel_start')->after('auto_channel_increment')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
