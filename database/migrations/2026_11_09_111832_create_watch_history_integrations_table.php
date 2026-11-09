<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trakt / SIMKL accounts the server sends Playlist Auth watch progress
     * to, the Playlist Auths each one covers, and what was last sent per
     * progress row so a title is marked watched once, not on every save.
     */
    public function up(): void
    {
        Schema::create('watch_history_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('provider'); // 'trakt' | 'simkl'
            $table->boolean('enabled')->default(true);
            $table->boolean('push_progress')->default(true);
            $table->string('account_name')->nullable();
            $table->text('access_token')->nullable(); // encrypted at app layer via Eloquent cast
            $table->text('refresh_token')->nullable(); // encrypted at app layer via Eloquent cast
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'enabled']);
        });

        Schema::create('playlist_auth_watch_history_integration', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_auth_id')->constrained()->cascadeOnDelete();
            $table->foreignId('watch_history_integration_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['playlist_auth_id', 'watch_history_integration_id'], 'pa_whi_unique');
        });

        Schema::create('watch_history_sync_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('watch_history_integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('viewer_watch_progress_id')->constrained('viewer_watch_progress')->cascadeOnDelete();
            $table->boolean('completed')->default(false);
            $table->decimal('progress', 5, 2)->default(0);
            $table->timestamp('pushed_at')->nullable();
            $table->timestamps();

            $table->unique(['watch_history_integration_id', 'viewer_watch_progress_id'], 'whss_integration_progress_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_history_sync_states');
        Schema::dropIfExists('playlist_auth_watch_history_integration');
        Schema::dropIfExists('watch_history_integrations');
    }
};
