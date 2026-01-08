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
        // Update channels table column types to text
        Schema::table('channels', function (Blueprint $table) {
            $table->text('title')->nullable()->change();
            $table->text('name')->nullable()->change();
            $table->text('group')->nullable()->change();
            $table->text('group_internal')->nullable()->change();
            $table->text('stream_id')->nullable()->change();
            $table->text('lang')->nullable()->change();
            $table->text('country')->nullable()->change();
            $table->text('import_batch_no')->nullable()->change();
            $table->text('title_custom')->nullable()->change();
            $table->text('name_custom')->nullable()->change();
            $table->text('stream_id_custom')->nullable()->change();
            $table->text('catchup')->nullable()->change();
            $table->text('catchup_source')->nullable()->change();
            $table->text('station_id')->nullable()->change();
            $table->text('tvg_shift')->nullable()->change();
            $table->text('container_extension')->nullable()->change();
            $table->text('year')->nullable()->change();
            $table->text('rating')->nullable()->change();
        });

        // Update playlist_sync_status_logs table column types to text
        Schema::table('playlist_sync_status_logs', function (Blueprint $table) {
            $table->text('name')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert playlist_sync_status_logs table column types back to original
        Schema::table('playlist_sync_status_logs', function (Blueprint $table) {
            $table->string('name')->change();
        });

        // Revert channels table column types back to original
        Schema::table('channels', function (Blueprint $table) {
            $table->string('title')->nullable()->change();
            $table->string('name')->nullable()->change();
            $table->string('group')->nullable()->change();
            $table->string('group_internal')->nullable()->change();
            $table->string('stream_id')->nullable()->change();
            $table->string('lang')->nullable()->change();
            $table->string('country')->nullable()->change();
            $table->string('import_batch_no')->nullable()->change();
            $table->string('title_custom')->nullable()->change();
            $table->string('name_custom')->nullable()->change();
            $table->string('stream_id_custom')->nullable()->change();
            $table->string('catchup')->nullable()->change();
            $table->string('catchup_source')->nullable()->change();
            $table->string('station_id')->nullable()->change();
            $table->string('tvg_shift')->nullable()->change();
            $table->string('container_extension')->nullable()->change();
            $table->string('year')->nullable()->change();
            $table->string('rating')->nullable()->change();
        });
    }
};
