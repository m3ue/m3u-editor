<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * All FKs live on this new, empty table on purpose: channels/episodes are
     * written continuously by the Process and Sync job chains and DDL there
     * takes locks. The indexes on media_channel_id/media_episode_id keep the
     * FK cascades cheap when SyncMediaServer::cleanupStaleRecords bulk-deletes
     * stale media rows.
     *
     * Index/uniqueness are declared as separate statements: chained onto
     * ->constrained() they land on the ForeignKeyDefinition and are silently
     * ignored.
     */
    public function up(): void
    {
        Schema::create('media_source_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_id')->constrained('playlists')->cascadeOnDelete();
            $table->foreignId('media_server_integration_id')->constrained('media_server_integrations')->cascadeOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained('channels')->cascadeOnDelete();
            $table->foreignId('episode_id')->nullable()->constrained('episodes')->cascadeOnDelete();
            $table->foreignId('media_channel_id')->nullable()->constrained('channels')->cascadeOnDelete();
            $table->foreignId('media_episode_id')->nullable()->constrained('episodes')->cascadeOnDelete();
            $table->string('match_key');
            $table->timestamps();

            $table->unique('channel_id');
            $table->unique('episode_id');
            $table->index('media_channel_id');
            $table->index('media_episode_id');
            $table->index('playlist_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_source_matches');
    }
};
