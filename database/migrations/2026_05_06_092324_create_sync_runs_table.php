<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `sync_runs` is the source of truth for individual playlist sync attempts.
     * It supersedes the JSON `processing` / `errors` / progress columns on
     * `playlists`, which will continue to be written for backwards compatibility
     * during the migration period (Step 8).
     */
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('playlist_id')
                ->constrained('playlists')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // High-level shape of this run: 'full', 'live_only', 'vod_only',
            // 'series_only', 'series_metadata_only', 'refresh', etc. Free-form
            // string rather than enum because new sync types are added often.
            $table->string('kind', 32)->default('full');

            // What initiated the run: 'manual', 'scheduled', 'api',
            // 'observer', 'auto_sync', 'sync_command', etc.
            $table->string('trigger', 32)->default('manual');

            // SyncRunStatus backed enum: pending|running|completed|failed|cancelled.
            $table->string('status', 16)->default('pending');

            // Per-phase ledger keyed by phase slug, e.g.
            //   {
            //     "discovery": {"status": "completed", "started_at": "...", "finished_at": "...", "meta": {...}},
            //     "vod_discovery": {...},
            //     "series_metadata": {...},
            //     "tmdb_fetch": {...},
            //     "find_replace": {...},
            //     "strm_sync_vod": {...},
            //   }
            $table->jsonb('phases')->default('{}');

            // Aggregate error log for the run; phase-specific errors live inside
            // each phase entry under `phases.<slug>.error`.
            $table->jsonb('errors')->nullable();

            // Arbitrary key/value scratch space (counts, batch numbers,
            // queue connection used, force flags, etc.).
            $table->jsonb('meta')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            // Hot-path lookups: latest run for a playlist, active runs.
            $table->index(['playlist_id', 'status']);
            $table->index(['playlist_id', 'started_at']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
