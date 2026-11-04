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
        Schema::table('dvr_recordings', function (Blueprint $table) {
            $table->dateTime('file_missing_since')->nullable()->after('file_size_bytes');
            $table->index(['dvr_setting_id', 'file_path'], 'dvr_recordings_setting_file_path_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dvr_recordings', function (Blueprint $table) {
            $table->dropIndex('dvr_recordings_setting_file_path_idx');
            $table->dropColumn('file_missing_since');
        });
    }
};
