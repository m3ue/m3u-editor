<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per player connection the proxy served (Proxy > Watch History):
     * who watched what, from which client, and for how long. Content details
     * are snapshotted so a row still reads correctly after the channel or
     * episode is removed by a later sync.
     */
    public function up(): void
    {
        Schema::create('stream_watch_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playlist_auth_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('playlist_viewer_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('playlist');

            // What was watched: 'live' | 'vod' | 'episode'
            $table->string('content_type', 16);
            $table->unsignedBigInteger('channel_id')->nullable();
            $table->unsignedBigInteger('episode_id')->nullable();
            $table->unsignedBigInteger('series_id')->nullable();
            $table->string('title', 512)->nullable();
            $table->string('episode_title', 512)->nullable();
            $table->unsignedSmallInteger('season_number')->nullable();
            $table->unsignedSmallInteger('episode_number')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedBigInteger('tmdb_id')->nullable();
            $table->string('imdb_id', 20)->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->string('programme_title', 512)->nullable();

            // Who and how
            $table->string('proxy_stream_id', 128);
            $table->string('proxy_client_id', 191);
            $table->string('username')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('is_transcoded')->default(false);
            $table->boolean('is_timeshift')->default(false);
            $table->unsignedBigInteger('stream_profile_id')->nullable();
            $table->unsignedBigInteger('provider_profile_id')->nullable();
            $table->json('media_info')->nullable();
            $table->json('metadata')->nullable();

            // When and how much
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedBigInteger('bytes_served')->nullable();
            $table->string('end_reason', 32)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
            $table->index(['proxy_client_id', 'proxy_stream_id']);
            $table->index('ended_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stream_watch_sessions');
    }
};
