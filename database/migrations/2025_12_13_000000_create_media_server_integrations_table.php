<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Media Server Integrations table for Emby/Jellyfin connectivity.
     * This is a standalone "sidecar" table - completely independent of existing tables.
     */
    public function up(): void
    {
        Schema::create('media_server_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Friendly name for the integration (e.g., "Living Room Jellyfin")
            $table->string('type'); // 'emby' or 'jellyfin'
            $table->string('host'); // FQDN or IP address (e.g., "192.168.1.100" or "media.example.com")
            $table->unsignedInteger('port')->default(8096); // Default Emby/Jellyfin port
            $table->text('api_key'); // API key for authentication (encrypted at app level)
            $table->boolean('enabled')->default(true); // Enable/disable the integration
            $table->boolean('ssl')->default(false); // Use HTTPS
            $table->string('genre_handling')->default('primary'); // 'primary' = first genre, 'all' = all genres
            $table->boolean('import_movies')->default(true); // Import movies as VOD channels
            $table->boolean('import_series')->default(true); // Import series/episodes
            $table->timestamp('last_synced_at')->nullable(); // Last successful sync timestamp
            $table->jsonb('sync_stats')->nullable(); // Sync statistics (items synced, errors, etc.)
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('playlist_id')->nullable()->constrained()->nullOnDelete(); // Auto-created playlist for this integration
            $table->timestamps();

            $table->index(['user_id', 'enabled']);
            $table->index('playlist_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_server_integrations');
    }
};
