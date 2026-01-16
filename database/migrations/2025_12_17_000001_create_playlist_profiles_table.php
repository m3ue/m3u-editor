<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('playlist_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Profile identification
            $table->string('name')->nullable(); // Optional friendly name like "Account 1"
            $table->string('username');
            $table->string('password');

            // Capacity management
            $table->unsignedInteger('max_streams')->default(1); // From provider or manual override
            $table->unsignedInteger('priority')->default(0); // Lower = tried first
            $table->boolean('enabled')->default(true);
            $table->boolean('is_primary')->default(false); // True for the original xtream_config account

            // Provider metadata (cached from API)
            $table->jsonb('provider_info')->nullable(); // Full user_info response from provider
            $table->timestamp('provider_info_updated_at')->nullable();

            $table->timestamps();

            // Indexes for efficient profile selection
            $table->index(['playlist_id', 'enabled', 'priority'], 'idx_profile_selection');
            $table->index(['playlist_id', 'is_primary'], 'idx_profile_primary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('playlist_profiles');
    }
};
