<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A playlist's M3U TV Home screen: the order of the app's sections,
     * whether each shows in the nav and on Home, and each section's rows.
     * `sections` follows TvHomeScreenLayout.
     *
     * It belongs to exactly one Playlist, Custom Playlist, Merged Playlist or
     * Playlist Alias (one Home screen each), through separate foreign keys so
     * deleting the playlist deletes its Home screen.
     */
    public function up(): void
    {
        Schema::create('tv_home_screens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playlist_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('custom_playlist_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('merged_playlist_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('playlist_alias_id')->nullable()->unique()->constrained('playlist_aliases')->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('mode')->default('default');
            $table->json('sections')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_home_screens');
    }
};
