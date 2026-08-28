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
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->foreignId('merged_playlist_id')
                ->nullable()
                ->after('custom_playlist_id')
                ->constrained()
                ->nullOnDelete();
        });

        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->index(['merged_playlist_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->dropIndex(['merged_playlist_id']);
            $table->dropConstrainedForeignId('merged_playlist_id');
        });
    }
};
