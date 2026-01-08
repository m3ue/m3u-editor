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
        // Allow the playlist_id column to be nullable
        Schema::table('channels', function (Blueprint $table) {
            // Drop the existing foreign key constraint
            $table->dropForeign(['playlist_id']);

            // Make the playlist_id column nullable
            $table->unsignedBigInteger('playlist_id')->nullable()->change();

            // Re-add the foreign key constraint with cascade on delete but allowing nulls
            $table->foreign('playlist_id')->references('id')->on('playlists')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            // Drop the foreign key constraint
            $table->dropForeign(['playlist_id']);

            // Make the playlist_id column non-nullable again
            $table->unsignedBigInteger('playlist_id')->nullable(false)->change();

            // Re-add the original foreign key constraint
            $table->foreign('playlist_id')->references('id')->on('playlists')->onDelete('cascade')->onUpdate('cascade');
        });
    }
};
