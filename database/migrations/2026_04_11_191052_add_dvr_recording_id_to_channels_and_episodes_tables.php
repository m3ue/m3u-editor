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
        Schema::table('channels', function (Blueprint $table) {
            $table->unsignedBigInteger('dvr_recording_id')->nullable()->after('probe_enabled');
            $table->foreign('dvr_recording_id')
                ->references('id')
                ->on('dvr_recordings')
                ->nullOnDelete();
        });

        Schema::table('episodes', function (Blueprint $table) {
            $table->unsignedBigInteger('dvr_recording_id')->nullable()->after('info');
            $table->foreign('dvr_recording_id')
                ->references('id')
                ->on('dvr_recordings')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropForeign(['dvr_recording_id']);
            $table->dropColumn('dvr_recording_id');
        });

        Schema::table('episodes', function (Blueprint $table) {
            $table->dropForeign(['dvr_recording_id']);
            $table->dropColumn('dvr_recording_id');
        });
    }
};
