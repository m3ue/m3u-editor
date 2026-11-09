<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reusable M3U TV app configurations. Each Playlist Auth can point at one,
     * and the owner may mark one as the default for logins without their own.
     * `settings` maps a TvClientSettings key to {mode, value}.
     */
    public function up(): void
    {
        Schema::create('tv_client_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_client_profiles');
    }
};
