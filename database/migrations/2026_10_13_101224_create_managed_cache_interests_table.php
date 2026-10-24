<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track which cached files a managed-publishing consumer is interested in.
     *
     * Interests are additive metadata. They reference a CachedContentFile and
     * an integration mapping, but they never own or delete the cached file.
     */
    public function up(): void
    {
        Schema::create('managed_cache_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cached_content_file_id')->constrained('cached_content_files')->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained('media_server_integrations')->cascadeOnDelete();
            $table->uuid('mapping_uuid');
            $table->string('canonical_id');
            $table->string('variant_key')->nullable();
            $table->string('consumer_id');
            $table->timestamp('watched_at')->nullable();
            $table->timestamp('release_after')->nullable();
            $table->timestamps();

            $table->unique(['cached_content_file_id', 'integration_id', 'mapping_uuid', 'consumer_id'], 'managed_cache_interests_unique_tuple');
            $table->index(['integration_id', 'mapping_uuid', 'canonical_id', 'variant_key']);
            $table->index('release_after');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_cache_interests');
    }
};
