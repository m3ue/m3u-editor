<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-playlist virtual "dynamic groups" computed from TMDB (Trending,
     * Popular, In Theatres, Top <Genre>, by TV Network, by Streaming Provider).
     *
     * Membership is tracked in a separate polymorphic table so a single channel
     * or series can belong to many groups at once — different from the
     * existing `groups`/`categories` scalar FK on channels/series.
     *
     * Rows are owned by a Playlist (cascadeOnDelete) and scoped by the
     * (playlist_id, type, source, name) unique key so re-runs upsert cleanly.
     */
    public function up(): void
    {
        Schema::create('dynamic_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');                  // 'vod' | 'series'
            $table->string('source');                // 'trending' | 'popular' | 'now_playing' | 'upcoming' | 'top_genre' | 'tmdb_network' | 'provider'
            $table->string('name');                  // display name / Xtream category name
            $table->json('tmdb_params')->nullable(); // per-source knobs (genre_id, network_id, provider_id, region, time_window, pages)
            $table->integer('sort_order')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['playlist_id', 'type', 'source', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamic_groups');
    }
};
