<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cached_content_activity_leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cached_content_file_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained('media_server_integrations')->cascadeOnDelete();
            $table->uuid('mapping_uuid')->nullable();
            $table->string('revision', 64)->nullable();
            $table->string('canonical_id')->nullable();
            $table->string('variant_key')->nullable();
            $table->string('kind');
            $table->uuid('lease_id');
            $table->unsignedBigInteger('sequence')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('last_activity_at');
            $table->timestamps();

            $table->unique(['integration_id', 'kind', 'lease_id'], 'cached_content_activity_leases_unique_lease');
            $table->index(['cached_content_file_id', 'expires_at']);
            $table->index(['integration_id', 'mapping_uuid', 'canonical_id', 'variant_key', 'expires_at'], 'cached_content_activity_leases_target_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cached_content_activity_leases');
    }
};
