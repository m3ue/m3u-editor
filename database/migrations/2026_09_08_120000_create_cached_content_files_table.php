<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per cached VOD channel or series episode.
     *
     * Each row is keyed to the exact source item it was downloaded for
     * (`cacheable_type` + `cacheable_id`), so two channels that share a
     * TMDB id (e.g. a German and an English release of the same film) each
     * get their own file. `content_fingerprint` is the TMDB/TVDB identity
     * and is only used to find a copy shared by another of the same
     * user's playlists (`playlists.share_cache_across_playlists`).
     */
    public function up(): void
    {
        Schema::create('cached_content_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('playlist_id')->nullable()->constrained('playlists')->nullOnDelete();
            $table->morphs('cacheable');
            $table->string('content_type'); // 'movie' | 'episode'
            $table->string('tmdb_id')->nullable();
            $table->string('tvdb_id')->nullable();
            $table->unsignedInteger('season_number')->nullable();
            $table->unsignedInteger('episode_number')->nullable();
            $table->string('quality')->nullable();
            $table->string('content_fingerprint');
            $table->string('title', 500)->nullable();
            $table->string('disk')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->bigInteger('bytes_downloaded')->nullable();
            $table->bigInteger('bytes_expected')->nullable();
            $table->bigInteger('bytes_per_second')->nullable();
            $table->timestamp('last_progress_at')->nullable();
            $table->string('status')->default('pending'); // CachedContentFileStatus
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamps();

            $table->unique(['cacheable_type', 'cacheable_id'], 'cached_content_files_cacheable_unique');
            $table->index(['content_fingerprint', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cached_content_files');
    }
};
