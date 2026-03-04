<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Direct assignment on Series
        Schema::table('series', function (Blueprint $table) {
            $table->foreignId('stream_file_setting_id')
                ->nullable()
                ->after('sync_location')
                ->constrained('stream_file_settings')
                ->nullOnDelete();
        });

        // Direct assignment on VOD Channels
        Schema::table('channels', function (Blueprint $table) {
            $table->foreignId('stream_file_setting_id')
                ->nullable()
                ->after('sync_location')
                ->constrained('stream_file_settings')
                ->nullOnDelete();
        });

        // Group-level assignment (for VOD channels)
        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('stream_file_setting_id')
                ->nullable()
                ->after('type')
                ->constrained('stream_file_settings')
                ->nullOnDelete();
        });

        // Category-level assignment (for Series)
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('stream_file_setting_id')
                ->nullable()
                ->after('enabled')
                ->constrained('stream_file_settings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('series', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stream_file_setting_id');
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stream_file_setting_id');
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stream_file_setting_id');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stream_file_setting_id');
        });
    }
};
