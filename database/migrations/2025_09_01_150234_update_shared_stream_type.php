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
        if (Schema::hasColumn('shared_streams', 'format')) {
            Schema::table('shared_streams', function (Blueprint $table) {
                // Drop indexes that reference the format column first
                $table->dropIndex(['format', 'status']); // shared_streams_format_status_index
                $table->dropColumn('format');
            });
        }

        Schema::table('shared_streams', function (Blueprint $table) {
            $table->enum('format', ['ts', 'hls', 'mkv', 'mp4'])->default('ts');
            // Recreate the index
            $table->index(['format', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shared_streams', function (Blueprint $table) {
            // Drop the index first
            $table->dropIndex(['format', 'status']);
            $table->dropColumn('format');
        });

        // Recreate the original format column
        Schema::table('shared_streams', function (Blueprint $table) {
            $table->enum('format', ['ts', 'hls'])->default('ts');
            // Recreate the index
            $table->index(['format', 'status']);
        });
    }
};
