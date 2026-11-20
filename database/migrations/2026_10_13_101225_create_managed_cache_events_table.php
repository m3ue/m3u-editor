<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotency journal for managed cache-control actions.
     *
     * Each row records a redacted response for a single user+integration+
     * action+request tuple so repeated identical requests return the same
     * outcome without side effects.
     */
    public function up(): void
    {
        Schema::create('managed_cache_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained('media_server_integrations')->cascadeOnDelete();
            $table->string('action'); // prefetch|playback|release
            $table->uuid('request_id');
            $table->string('payload_hash');
            $table->json('redacted_response');
            $table->timestamps();

            $table->unique(['user_id', 'integration_id', 'action', 'request_id'], 'managed_cache_events_unique_request');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_cache_events');
    }
};
